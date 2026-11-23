<?php

namespace Utopia\Balancer;

class Balancer
{
    private Algorithm $algo;

    /**
     * @var callable[]
     */
    private array $filters = [];

    /**
     * @var Option[]
     */
    private array $options = [];

    public function __construct(Algorithm $algo)
    {
        $this->algo = $algo;
    }

    public function getAlgo(): Algorithm
    {
        return $this->algo;
    }

    public function addOption(Option $option): self
    {
        $this->options[] = $option;
        return $this;
    }

    /**
     * @return Option[]
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function addFilter(callable $filter): self
    {
        $this->filters[] = $filter;
        return $this;
    }

    /**
     * Every option that passed all filters, in the order they were added.
     *
     * `run()` narrows this to one option through the algorithm. Callers that
     * have to act on all of them — fanning a request out to every option that
     * qualifies, rather than balancing between them — read them here.
     *
     * @return Option[]
     */
    public function getFilteredOptions(): array
    {
        $options = $this->options;

        foreach ($this->filters as $filter) {
            $options = \array_filter($options, $filter);
        }

        return \array_values($options);
    }

    public function run(): ?Option
    {
        $options = $this->getFilteredOptions();

        if (\count($options) === 0) {
            return null;
        }

        return $this->algo->run($options);
    }
}
