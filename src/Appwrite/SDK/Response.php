<?php

namespace Appwrite\SDK;

readonly class Response
{
    /**
     * @param int $code
     * @param string|array $model
     */
    public function __construct(
        private int $code,
        private string|array $model
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
}
