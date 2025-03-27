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
    )
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    public function setDefault(mixed $default): void
    {
        $this->default = $default;
    }

    public function getValidator(): mixed
    {
        return $this->validator;
    }

    public function setValidator(mixed $validator): void
    {
        $this->validator = $validator;
    }

    public function isOptional(): bool
    {
        return $this->optional;
    }

    public function setOptional(bool $optional): void
    {
        $this->optional = $optional;
    }
}