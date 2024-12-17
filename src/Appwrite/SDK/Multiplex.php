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
    function __construct(
        private string $name,
        private array $parameters,
        private array $required,
        private string $responseModel
    )
    {}
}