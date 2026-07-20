<?php

namespace Laravel\Ai\Tools;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Stringable;

class ExecuteTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    /**
     * A runner that executes the target tool, injected by the generation loop so the
     * real invocation flows through the tool-invocation callbacks (events, auditing).
     *
     * @var (Closure(Tool, array<string, mixed>): string)|null
     */
    protected ?Closure $invoker = null;

    /**
     * @param  array<int, Tool>  $catalog
     */
    public function __construct(
        protected array $catalog,
        protected string $toolName = 'execute_tool',
        protected string $searchToolName = 'search_tools',
    ) {}

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
    public function description(): Stringable|string
    {
        return 'Run one of the tools returned by '.$this->searchToolName.'. Pass the tool name and the arguments matching its schema.';
    }

    /**
     * Get the number of tools in the catalog this executor can run.
     */
    public function catalogSize(): int
    {
        return count($this->catalog);
    }

    /**
     * Set the runner used to execute the target tool.
     *
     * @param  (Closure(Tool, array<string, mixed>): string)|null  $invoker
     */
    public function invokeUsing(?Closure $invoker): static
    {
        $this->invoker = $invoker;

        return $this;
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $name = (string) $request->string('tool');

        $tool = $this->find($name);

        if (! $tool instanceof Tool) {
            return 'Unknown tool "'.$name.'". Use '.$this->searchToolName.' to discover the tools you can run.';
        }

        [$arguments, $error] = $this->parseArguments($request['arguments'] ?? null);

        if ($error !== null) {
            return $error;
        }

        $run = $this->invoker ?? fn (Tool $target, array $targetArguments): string => (string) $target->handle(new Request($targetArguments));

        return (string) $run($tool, $arguments);
    }

    /**
     * Determine whether the requested execution should request approval, delegating to the target tool.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        $tool = $this->find((string) $request->string('tool'));

        if (! $tool instanceof Approvable) {
            return false;
        }

        return $tool->shouldRequestApproval(new Request($this->parseArguments($request['arguments'] ?? null)[0])) ?? false;
    }

    /**
     * Find a catalog tool by name.
     */
    protected function find(string $name): ?Tool
    {
        foreach ($this->catalog as $tool) {
            if ($tool instanceof Tool && ToolNameResolver::resolve($tool) === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Parse the raw `arguments` value into an argument array, tolerating both a JSON string and a decoded object.
     *
     * @return array{array<string, mixed>, ?string}
     */
    protected function parseArguments(mixed $raw): array
    {
        if (is_array($raw)) {
            return [$raw, null];
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [[], null];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded)
            ? [$decoded, null]
            : [[], 'The "arguments" value must be a JSON object matching the tool schema. Received: '.$raw];
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tool' => $schema
                ->string()
                ->description('The name of the tool to run, as returned by '.$this->searchToolName.'.')
                ->required(),
            'arguments' => $schema
                ->string()
                ->description('A JSON object of arguments for the tool, matching the schema returned by '.$this->searchToolName.'. Use "{}" when the tool takes no arguments.'),
        ];
    }
}
