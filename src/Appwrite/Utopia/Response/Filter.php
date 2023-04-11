<?php

namespace Appwrite\Utopia\Response;

abstract class Filter
{
    /**
     * Parse the content to another format.
     *
     * @param  array  $content
     * @param  string  $model
     * @return array
     */
    abstract public function parse(array $content, string $model): array;
}
