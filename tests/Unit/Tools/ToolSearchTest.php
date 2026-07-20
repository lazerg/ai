<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Tools\ExecuteTool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\SearchTools;
use Laravel\Ai\Tools\ToolSearch;

test('a tool search expands into a search tool and an execute tool sharing the catalog', function (): void {
    [$search, $execute] = ToolSearch::for([new ToolSearchWeatherTool, 'not-a-tool'])
        ->expand(fn ($tool) => $tool);

    expect($search)->toBeInstanceOf(SearchTools::class)
        ->and($search->name())->toBe('search_tools')
        ->and($execute)->toBeInstanceOf(ExecuteTool::class)
        ->and($execute->name())->toBe('execute_tool')
        ->and($execute->catalogSize())->toBe(1);
});

test('as() namespaces both tool names so multiple catalogs do not collide', function (): void {
    [$defaultSearch, $defaultExecute] = ToolSearch::for([new ToolSearchWeatherTool])->expand(fn ($tool) => $tool);
    [$namedSearch, $namedExecute] = ToolSearch::for([new ToolSearchEmailTool])->as('mail')->expand(fn ($tool) => $tool);

    expect($namedSearch->name())->toBe('search_mail')
        ->and($namedExecute->name())->toBe('execute_mail')
        ->and($namedSearch->name())->not->toBe($defaultSearch->name())
        ->and($namedExecute->name())->not->toBe($defaultExecute->name());
});

test('search ranks the most relevant tool first and returns its argument schema', function (): void {
    $weather = new ToolSearchWeatherTool;

    [$search] = ToolSearch::for([new ToolSearchEmailTool, $weather])->expand(fn ($tool) => $tool);

    expect(rankTools($search, 'what is the weather like today'))->toBe([$weather]);

    $result = $search->handle(new Request(['query' => 'weather forecast']));

    expect($result)->toContain('get_weather')
        ->and($result)->toContain('execute_tool')
        ->and($result)->toContain('city');
});

test('search respects the configured limit', function (): void {
    [$search] = ToolSearch::for([
        new ToolSearchWeatherTool,
        new ToolSearchEmailTool,
        new ToolSearchCalculatorTool,
    ])->limit(1)->expand(fn ($tool) => $tool);

    expect(rankTools($search, 'send email weather add numbers'))->toHaveCount(1);
});

test('short query tokens do not pollute ranking but prefixes still match', function (): void {
    $weather = new ToolSearchWeatherTool;

    [$search] = ToolSearch::for([$weather])->expand(fn ($tool) => $tool);

    // "at" is a substring of "temperature" in the corpus, but must not score.
    expect(rankTools($search, 'at'))->toBe([]);

    // A prefix of a real token still earns partial credit.
    expect(rankTools($search, 'weath'))->toBe([$weather]);
});

test('search reports when nothing matches', function (): void {
    [$search] = ToolSearch::for([new ToolSearchWeatherTool])->expand(fn ($tool) => $tool);

    expect($search->handle(new Request(['query' => 'zzzzz nonexistent capability'])))
        ->toContain('No matching tools');
});

test('a custom ranking callback overrides the built-in lexical search', function (): void {
    $email = new ToolSearchEmailTool;

    [$search] = ToolSearch::for([new ToolSearchWeatherTool, $email])
        ->using(fn (string $query, array $catalog): array => [$email])
        ->expand(fn ($tool) => $tool);

    expect(rankTools($search, 'the weather is sunny'))->toBe([$email]);
});

test('a custom ranking callback that returns a non-iterable degrades gracefully', function (): void {
    [$search] = ToolSearch::for([new ToolSearchWeatherTool])
        ->using(fn (string $query, array $catalog) => null)
        ->expand(fn ($tool) => $tool);

    expect($search->handle(new Request(['query' => 'weather'])))->toContain('No matching tools');
});

test('execute runs the named catalog tool with JSON string arguments', function (): void {
    [, $execute] = ToolSearch::for([new ToolSearchWeatherTool])->expand(fn ($tool) => $tool);

    expect($execute->handle(new Request(['tool' => 'get_weather', 'arguments' => '{"city":"Paris"}'])))
        ->toBe('It is sunny in Paris.');
});

test('execute also accepts arguments already decoded to an array', function (): void {
    [, $execute] = ToolSearch::for([new ToolSearchWeatherTool])->expand(fn ($tool) => $tool);

    expect($execute->handle(new Request(['tool' => 'get_weather', 'arguments' => ['city' => 'Paris']])))
        ->toBe('It is sunny in Paris.');
});

test('execute reports invalid JSON arguments instead of dropping them', function (): void {
    [, $execute] = ToolSearch::for([new ToolSearchWeatherTool])->expand(fn ($tool) => $tool);

    expect($execute->handle(new Request(['tool' => 'get_weather', 'arguments' => 'city=Paris'])))
        ->toContain('must be a JSON object');
});

test('execute reports an unknown tool without throwing', function (): void {
    [, $execute] = ToolSearch::for([new ToolSearchWeatherTool])->expand(fn ($tool) => $tool);

    expect($execute->handle(new Request(['tool' => 'missing_tool', 'arguments' => '{}'])))
        ->toContain('Unknown tool');
});

test('execute lets a failing tool throw instead of swallowing the error', function (): void {
    [, $execute] = ToolSearch::for([new ToolSearchThrowingTool])->expand(fn ($tool) => $tool);

    expect(fn () => $execute->handle(new Request(['tool' => 'boom', 'arguments' => '{}'])))
        ->toThrow(RuntimeException::class, 'kaboom');
});

test('execute routes the target tool through the injected invoker', function (): void {
    [, $execute] = ToolSearch::for([new ToolSearchWeatherTool])->expand(fn ($tool) => $tool);

    $seen = [];

    $execute->invokeUsing(function (Tool $tool, array $arguments) use (&$seen): string {
        $seen[] = [$tool->name(), $arguments];

        return 'routed';
    });

    expect($execute->handle(new Request(['tool' => 'get_weather', 'arguments' => '{"city":"Paris"}'])))->toBe('routed')
        ->and($seen)->toBe([['get_weather', ['city' => 'Paris']]]);
});

test('execute serializes its arguments parameter as an open string through provider schema serialization', function (): void {
    [, $execute] = ToolSearch::for([new ToolSearchWeatherTool])->expand(fn ($tool) => $tool);

    $serialized = (new ObjectSchema($execute->schema(new JsonSchemaTypeFactory)))->toSchema();

    expect($serialized['properties']['arguments']['type'])->toBe('string');
});

test('execute delegates approval to the target catalog tool', function (): void {
    [, $execute] = ToolSearch::for([new ToolSearchGatedTool])->expand(fn ($tool) => $tool);

    $gated = $execute->shouldRequestApproval(new Request(['tool' => 'gated', 'arguments' => '{}']));
    $ungated = $execute->shouldRequestApproval(new Request(['tool' => 'get_weather', 'arguments' => '{}']));

    expect($gated)->toBeInstanceOf(Approval::class)
        ->and($ungated)->toBeNull();
});

test('tool search can force approval for every tool or disable it entirely', function (): void {
    [, $forced] = ToolSearch::for([new ToolSearchWeatherTool])->requireApproval('Always confirm')->expand(fn ($tool) => $tool);
    [, $disabled] = ToolSearch::for([new ToolSearchGatedTool])->withoutApproval()->expand(fn ($tool) => $tool);

    expect($forced->shouldRequestApproval(new Request(['tool' => 'get_weather'])))->toBeInstanceOf(Approval::class)
        ->and($disabled->shouldRequestApproval(new Request(['tool' => 'gated'])))->toBeNull();
});

function rankTools(SearchTools $search, string $query): array
{
    return (fn () => $this->search($query))->call($search);
}

class ToolSearchWeatherTool implements Tool
{
    public function name(): string
    {
        return 'get_weather';
    }

    public function description(): string
    {
        return 'Get the current weather and temperature for the given city.';
    }

    public function handle(Request $request): string
    {
        return 'It is sunny in '.$request->string('city').'.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['city' => $schema->string()->required()];
    }
}

class ToolSearchEmailTool implements Tool
{
    public function name(): string
    {
        return 'send_email';
    }

    public function description(): string
    {
        return 'Send an email message to a recipient.';
    }

    public function handle(Request $request): string
    {
        return 'sent';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['recipient' => $schema->string()->required()];
    }
}

class ToolSearchCalculatorTool implements Tool
{
    public function name(): string
    {
        return 'add_numbers';
    }

    public function description(): string
    {
        return 'Add two numbers together and return the sum.';
    }

    public function handle(Request $request): string
    {
        return '42';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['a' => $schema->integer()->required(), 'b' => $schema->integer()->required()];
    }
}

class ToolSearchThrowingTool implements Tool
{
    public function name(): string
    {
        return 'boom';
    }

    public function description(): string
    {
        return 'A tool that always fails.';
    }

    public function handle(Request $request): string
    {
        throw new RuntimeException('kaboom');
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

class ToolSearchGatedTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function name(): string
    {
        return 'gated';
    }

    public function description(): string
    {
        return 'A tool that requires approval before running.';
    }

    public function handle(Request $request): string
    {
        return 'ran';
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        return Approval::required('Needs a human');
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
