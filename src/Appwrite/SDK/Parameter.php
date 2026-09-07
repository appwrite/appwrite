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

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getDefault(): mixed
    {
        return $this->default === Undefined::Value ? null : $this->default;
    }

    public function hasDefault(): bool
    {
        return $this->default !== Undefined::Value;
    }

    public function getValidator(): mixed
    {
        return $this->validator;
    }

    public function getOptional(): bool
    {
        return $this->optional === Undefined::Value ? false : $this->optional;
    }

    public function hasOptional(): bool
    {
        return $this->optional !== Undefined::Value;
    }

    public function getHide(): bool
    {
        return $this->hide;
    }
}
