<?php

namespace Laravel\Ai\Tools;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\ObjectSchema;
use Throwable;
use Traversable;

class SearchTools implements Tool
{
    /**
     * Per-catalog-tool token counts, precomputed once so each search is O(catalog).
     *
     * @var array<int, array<string, int>>
     */
    protected array $tokenCounts;

    /**
     * @param  array<int, Tool>  $catalog
     * @param  (Closure(string, array<int, Tool>): iterable<mixed>)|null  $using
     */
    public function __construct(
        protected array $catalog,
        protected int $limit = 5,
        protected ?Closure $using = null,
        protected string $executeToolName = 'execute_tool',
        protected string $toolName = 'search_tools',
    ) {
        $this->tokenCounts = array_map(
            fn (Tool $tool): array => array_count_values($this->tokenize($this->corpusFor($tool))),
            $this->catalog,
        );
    }

    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return $this->toolName;
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Search the catalog of available tools by describing the capability you need. '.
            'Returns matching tool names and their argument schemas. To run one, call '.
            $this->executeToolName.' with the returned tool name and matching arguments.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $matches = $this->search((string) $request->string('query'));

        if ($matches === []) {
            return 'No matching tools were found. Try describing the capability you need differently.';
        }

        return $this->describeMatches($matches);
    }

    /**
     * Rank the catalog against the given query, returning the best matching tools.
     *
     * @return array<int, Tool>
     */
    protected function search(string $query): array
    {
        if ($this->using !== null) {
            $ranked = ($this->using)($query, $this->catalog);

            $ranked = match (true) {
                $ranked instanceof Traversable => iterator_to_array($ranked, false),
                is_array($ranked) => $ranked,
                default => [],
            };

            return array_slice(array_values(array_filter($ranked, fn ($tool): bool => $tool instanceof Tool)), 0, $this->limit);
        }

        return $this->lexicalSearch($query);
    }

    /**
     * Rank the catalog by query-term overlap against each tool's precomputed corpus.
     *
     * @return array<int, Tool>
     */
    protected function lexicalSearch(string $query): array
    {
        $terms = $this->tokenize($query);

        if ($terms === []) {
            return [];
        }

        $scored = [];

        foreach ($this->catalog as $index => $tool) {
            $counts = $this->tokenCounts[$index] ?? [];

            if ($counts === []) {
                continue;
            }

            $score = 0.0;

            foreach ($terms as $term) {
                if (isset($counts[$term])) {
                    $score += 1 + log(1 + $counts[$term]);

                    continue;
                }

                // Partial credit only for prefixes of longer tokens, and only for terms long
                // enough that a prefix match is meaningful — short terms match too much noise.
                if (strlen($term) >= 3) {
                    foreach ($counts as $token => $occurrences) {
                        if (str_starts_with((string) $token, $term)) {
                            $score += 0.5;

                            break;
                        }
                    }
                }
            }

            if ($score > 0) {
                $scored[] = ['score' => $score, 'index' => $index, 'tool' => $tool];
            }
        }

        usort($scored, fn (array $a, array $b): int => [$b['score'], $a['index']] <=> [$a['score'], $b['index']]);

        return array_slice(array_map(fn (array $entry): Tool => $entry['tool'], $scored), 0, $this->limit);
    }

    /**
     * Build the searchable text corpus for a tool.
     */
    protected function corpusFor(Tool $tool): string
    {
        $parts = [ToolNameResolver::resolve($tool), (string) $tool->description()];

        try {
            foreach (array_keys($tool->schema(new JsonSchemaTypeFactory)) as $parameter) {
                $parts[] = (string) $parameter;
            }
        } catch (Throwable) {
            // Ignore tools whose schema cannot be resolved while ranking.
        }

        return implode(' ', $parts);
    }

    /**
     * Split text into lower-cased alphanumeric tokens of more than one character.
     *
     * @return array<int, string>
     */
    protected function tokenize(string $text): array
    {
        $tokens = preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($tokens, fn (string $token): bool => strlen($token) > 1));
    }

    /**
     * Describe the matched tools for the model, listing each one's arguments.
     *
     * @param  array<int, Tool>  $matches
     */
    protected function describeMatches(array $matches): string
    {
        $lines = [];

        foreach ($matches as $index => $tool) {
            $lines[] = sprintf(
                "%d. %s — %s\n   arguments: %s",
                $index + 1,
                ToolNameResolver::resolve($tool),
                trim((string) $tool->description()),
                $this->schemaJson($tool),
            );
        }

        return 'Found '.count($matches).' matching tool(s). Call '.$this->executeToolName.
            " with the tool name and a JSON object of arguments to run one:\n\n".implode("\n\n", $lines);
    }

    /**
     * Render a tool's argument schema as a compact JSON string.
     */
    protected function schemaJson(Tool $tool): string
    {
        try {
            $schema = $tool->schema(new JsonSchemaTypeFactory);

            if ($schema === []) {
                return '{"type":"object","properties":{}}';
            }

            return json_encode((new ObjectSchema($schema))->toSchema()) ?: '{}';
        } catch (Throwable) {
            return '{}';
        }
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema
                ->string()
                ->description('A natural-language description of the capability or tool you need.')
                ->required(),
        ];
    }
}
