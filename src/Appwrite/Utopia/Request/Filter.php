<?php

namespace Appwrite\Utopia\Request;

abstract class Filter
{
    /**
     * Parse params to another format.
     *
     * @param array $content
     * @param string $model
     *
     * @return array
     */
    abstract public function parse(array $content, string $model): array;
}
