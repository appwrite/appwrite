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

    public function transform(): bool
    {
        foreach ($this->adapters as $adapter) {
            if (!$adapter->isValid($this->traits)) {
                return false;
            }
        }

        $output = $this->input;

        foreach ($this->adapters as $adapter) {
            $adapter->setInput($output);
            $adapter->transform();
            $output = $adapter->getOutput();
        }

        $this->output = $output;

        return true;
    }

    public function getOutput(): mixed
    {
        return $this->output;
    }
}
