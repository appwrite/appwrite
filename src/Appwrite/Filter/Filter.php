<?php

namespace Appwrite\Filter;

class Filter
{
    /**
     * @var array<mixed> $adapters
     */
    protected array $adapters;

    protected mixed $input;

    protected mixed $output;

    /**
     * @param array<Adapter> $adapters
     */
    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    public function setInput(mixed $input): self
    {
        $this->input = $input;
        return $this;
    }

    public function getOutput(): mixed
    {
        return $this->output;
    }

    public function addAdapter(Adapter $adapter): self
    {
        $this->adapters[] = $adapter;
        return $this;
    }

    public function filter(): bool
    {
        foreach ($this->adapters as $adapter) {
            if (!$adapter->isValid($this->input)) {
                return false;
            }
        }

        $output = $this->input;

        foreach ($this->adapters as $adapter) {
            $output = $adapter
                ->setInput($output)
                ->filter()
                ->getOutput();
        }

        $this->output = $output;

        return true;
    }
}
