<?php

namespace Appwrite\SDK;

class Response
{
    /**
     * @param int $code
     * @param string|array $model
     * @param string $description
     */
    public function __construct(
        private int $code,
        private string|array $model,
        private string $description = '',
    ) {
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function getModel(): string|array
    {
        return $this->model;
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}
