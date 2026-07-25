<?php

namespace Appwrite\SDK;

use Utopia\Validator;

class Parameter
{
    /**
     * @param string $name
     * @param string $description
     * @param mixed $default Explicit null overrides a route default with null; leave unset for no override
     * @param Validator|callable|null $validator
     * @param bool|Undefined $optional Leave unset for no override
     * @param bool $hide Omit this parameter from the generated specification while keeping it accepted at runtime
     */
    public function __construct(
        protected string $name,
        protected string $description = '',
        protected mixed $default = Undefined::Value,
        protected mixed $validator = null,
        protected bool|Undefined $optional = Undefined::Value,
        protected bool $hide = false,
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
        return $this->default === Undefined::Value ? null : $this->default;
    }

    public function hasDefault(): bool
    {
        return $this->default !== Undefined::Value;
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
        return $this->optional === Undefined::Value ? false : $this->optional;
    }

    public function hasOptional(): bool
    {
        return $this->optional !== Undefined::Value;
    }

    public function setOptional(bool $optional): static
    {
        $this->optional = $optional;
        return $this;
    }

    public function getHide(): bool
    {
        return $this->hide;
    }

    public function setHide(bool $hide): static
    {
        $this->hide = $hide;
        return $this;
    }
}
