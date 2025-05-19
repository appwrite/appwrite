<?php

namespace Appwrite\SDK;

use Utopia\Validator;

class Parameter
{
    /**
     * @param string $name
     * @param string $description
     * @param mixed|null $default
     * @param Validator|callable|null $validator
     * @param bool $optional
     */
    public function __construct(
        protected string $name,
        protected string $description = '',
        protected mixed $default = null,
        protected mixed $validator = null,
        protected bool $optional = false,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    public function setDefault(mixed $default): static
    {
        $this->default = $default;
        return $this;
    }

    public function getValidator(): mixed
    {
        return $this->validator;
    }

    public function setValidator(mixed $validator): static
    {
        $this->validator = $validator;
        return $this;
    }

    public function getOptional(): bool
    {
        return $this->optional;
    }

    public function setOptional(bool $optional): static
    {
        $this->optional = $optional;
        return $this;
    }
}
