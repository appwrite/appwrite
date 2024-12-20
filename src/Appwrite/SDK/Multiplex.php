<?php

namespace Appwrite\SDK;

class Multiplex
{
    /**
     * @param string $name
     * @param array<string> $parameters
     * @param array<string> $required
     * @param string $responseModel
     */
    public function __construct(
        private string $name,
        private array $parameters,
        private array $required,
        private string $responseModel
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getRequired(): array
    {
        return $this->required;
    }

    public function getResponseModel(): string
    {
        return $this->responseModel;
    }
}
