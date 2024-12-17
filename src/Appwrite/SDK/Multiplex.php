<?php

namespace Appwrite\SDK;

use JsonSerializable;

class Multiplex implements JsonSerializable
{
    /**
     * @param string $name
     * @param array<string> $parameters
     * @param array<string> $required
     * @param string $responseModel
     */
    function __construct(
        private string $name,
        private array $parameters,
        private array $required,
        private string $responseModel
    )
    {}

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'parameters' => $this->parameters,
            'required' => $this->required,
            'responseModel' => $this->responseModel,
        ];
    }
}