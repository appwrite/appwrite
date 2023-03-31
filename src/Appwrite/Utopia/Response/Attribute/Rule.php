<?php

namespace Appwrite\Utopia\Response;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Rule
{
    protected string $type;
    protected string $name;

    public function __construct(
        public string $description,
        public string $default,
        public string $example,
        public bool $required = true,
        public bool $array = false
    ) {
    }

    public function setType(string|array $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string|array
    {
        return $this->type;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getDefault(): string
    {
        return $this->default;
    }

    public function getExample(): string
    {
        return $this->example;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isArray(): bool
    {
        return $this->array;
    }
}
