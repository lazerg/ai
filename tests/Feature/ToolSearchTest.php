<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolSearch;

test('the agent searches for a deferred tool and runs it through the executor', function (): void {
    Event::fake([ToolInvoked::class]);

    ToolSearchAgent::fake([
        new ToolCall('c1', 'search_tools', ['query' => 'what is the weather']),
        new ToolCall('c2', 'execute_tool', ['tool' => 'get_weather', 'arguments' => '{"city":"Paris"}']),
        'The weather in Paris is sunny.',
    ]);

    $response = (new ToolSearchAgent)->prompt('What is the weather in Paris?');

    $names = $response->toolResults->map(fn ($result) => $result->name);

    expect($response->text)->toBe('The weather in Paris is sunny.')
        ->and($names)->toContain('search_tools')
        ->and($names)->toContain('execute_tool')
        ->and($response->toolResults->firstWhere('name', 'search_tools')->result)->toContain('get_weather')
        ->and($response->toolResults->firstWhere('name', 'execute_tool')->result)->toBe('It is sunny in Paris.');

    // The underlying catalog tool is audited under its own name, not just execute_tool.
    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $event): bool => $event->tool->name() === 'get_weather'
        && $event->arguments === ['city' => 'Paris']
        && $event->result === 'It is sunny in Paris.');
});

test('the deferred catalog tool is never exposed to the provider directly', function (): void {
    ToolSearchAgent::fake(['Nothing to do.']);

    $response = (new ToolSearchAgent)->prompt('Say hello.');

    // Only the two meta-tools are ever exposed; the catalog tool is reached solely through execute_tool.
    expect($response->text)->toBe('Nothing to do.');
});

class ToolSearchWeatherCatalogTool implements Tool
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

class ToolSearchAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant. Search for a tool when you do not already have one for the task.';
    }

    public function tools(): iterable
    {
        return [
            ToolSearch::for([
                new ToolSearchWeatherCatalogTool,
            ]),
        ];
    }
}
