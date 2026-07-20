<?php

namespace Laravel\Ai\Tools;

use Closure;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Tool;

class ToolSearch
{
    /**
     * The catalog of deferred tools the model can discover and run on demand.
     *
     * @var array<int, mixed>
     */
    protected array $catalog;

    /**
     * The maximum number of tools returned per search.
     */
    protected int $limit = 5;

    /**
     * A distinct name for this catalog, so multiple tool searches don't collide on tool names.
     */
    protected ?string $name = null;

    /**
     * The approval requirement applied to every execution through this catalog's executor.
     */
    protected Approval|bool|null $approvalRequirement = null;

    /**
     * A custom ranking callback: fn(string $query, array<int, Tool> $catalog): iterable<Tool>.
     *
     * @var (Closure(string, array<int, Tool>): iterable<mixed>)|null
     */
    protected ?Closure $using = null;

    /**
     * Create a new tool search over the given catalog of tools.
     *
     * @param  iterable<mixed>  $tools
     */
    public function __construct(iterable $tools)
    {
        $this->catalog = is_array($tools) ? array_values($tools) : iterator_to_array($tools, false);
    }

    /**
     * Create a new tool search over the given catalog of tools.
     *
     * @param  iterable<mixed>  $tools
     */
    public static function for(iterable $tools): self
    {
        return new self($tools);
    }

    /**
     * Set the maximum number of tools returned per search.
     */
    public function limit(int $limit): self
    {
        $this->limit = max(1, $limit);

        return $this;
    }

    /**
     * Give this catalog a distinct name so its tools don't collide with another tool search.
     *
     * The exposed tools become "search_{name}" and "execute_{name}".
     */
    public function as(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Require approval before any tool in this catalog is run.
     */
    public function requireApproval(?string $reason = null): self
    {
        $this->approvalRequirement = Approval::required($reason);

        return $this;
    }

    /**
     * Allow tools in this catalog to run without approval, overriding any gate on the target tool.
     */
    public function withoutApproval(): self
    {
        $this->approvalRequirement = false;

        return $this;
    }

    /**
     * Use a custom ranking callback in place of the built-in lexical search.
     *
     * @param  Closure(string, array<int, Tool>): iterable<mixed>  $using
     */
    public function using(Closure $using): self
    {
        $this->using = $using;

        return $this;
    }

    /**
     * Get the catalog of deferred tools.
     *
     * @return array<int, mixed>
     */
    public function catalog(): array
    {
        return $this->catalog;
    }

    /**
     * Expand into the pair of deferred meta-tools the model interacts with: search and execute.
     *
     * The catalog is resolved through the given resolver (e.g. Agent to AgentTool) but never
     * exposed to the provider directly; the model finds tools with search and runs them with execute.
     *
     * @param  Closure(mixed): mixed  $resolver
     * @return array<int, Tool>
     */
    public function expand(Closure $resolver): array
    {
        $catalog = array_values(array_filter(
            array_map($resolver, $this->catalog),
            fn ($tool): bool => $tool instanceof Tool,
        ));

        $searchName = $this->name === null ? 'search_tools' : 'search_'.$this->name;
        $executeName = $this->name === null ? 'execute_tool' : 'execute_'.$this->name;

        $execute = new ExecuteTool($catalog, $executeName, $searchName);

        if ($this->approvalRequirement instanceof Approval) {
            $execute->requireApproval($this->approvalRequirement->reason);
        } elseif ($this->approvalRequirement === false) {
            $execute->withoutApproval();
        }

        return [
            new SearchTools($catalog, $this->limit, $this->using, $executeName, $searchName),
            $execute,
        ];
    }
}
