<?php

namespace Appwrite\SDK;

class Response
{
    /**
     * @param int $code
     * @param string $model
     * @param string $description
     */
    function __construct(
        private int $code,
        private string $model,
        private string $description = '',
    )
    {}

    public function getCode(): int
    {
        return $this->code;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}