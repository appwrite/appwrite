<?php

namespace Appwrite\Transformation;

class Transformation
{
    /**
     * @var array<Adapter> $adapters
     */
    protected array $adapters;

    /**
     * @var array<mixed> $traits
     */
    protected array $traits;

    protected mixed $input;
    protected mixed $output;

    /**
     * @param array<Adapter> $adapters
     */
    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    /**
     * @param array<mixed> $traits
     */
    public function setTraits(array $traits): self
    {
        $this->traits = $traits;
        return $this;
    }

    public function setInput(mixed $input): self
    {
        $this->input = $input;
        return $this;
    }

    public function addAdapter(Adapter $adapter): self
    {
        $this->adapters[] = $adapter;
        return $this;
    }

    /**
     * Applies every valid adapter in order, skipping the rest.
     *
     * @return bool Whether any adapter applied
     */
    public function transform(): bool
    {
        $output = $this->input;
        $applied = false;

        foreach ($this->adapters as $adapter) {
            if (!$adapter->isValid($this->traits)) {
                continue;
            }

            $adapter->setInput($output);
            $adapter->transform();
            $output = $adapter->getOutput();
            $applied = true;
        }

        $this->output = $output;

        return $applied;
    }

    public function getOutput(): mixed
    {
        return $this->output;
    }
}
