<?php

namespace Utopia\Query;

use Utopia\Query\Exception\ValidationException;

trait QuotesIdentifiers
{
    protected string $wrapChar = '`';

    protected function quote(string $identifier): string
    {
        if ($identifier === '*') {
            return '*';
        }

        if (\preg_match('/[\x00-\x1f\x7f]/', $identifier) === 1) {
            throw new ValidationException('Identifier contains control character');
        }

        if (!\str_contains($identifier, '.')) {
            return $this->wrapChar
                . \str_replace($this->wrapChar, $this->wrapChar . $this->wrapChar, $identifier)
                . $this->wrapChar;
        }

        $segments = \explode('.', $identifier);
        $lastIndex = \count($segments) - 1;
        $wrapped = [];

        foreach ($segments as $index => $segment) {
            if ($segment === '*' && $index === $lastIndex) {
                $wrapped[] = '*';
                continue;
            }

            $wrapped[] = $this->wrapChar
                . \str_replace($this->wrapChar, $this->wrapChar . $this->wrapChar, $segment)
                . $this->wrapChar;
        }

        return \implode('.', $wrapped);
    }

    /**
     * Quote a single identifier without treating dots as qualifier separators.
     *
     * Use when the identifier is known to be atomic — e.g. a column name in a
     * CREATE TABLE definition where the dot is a literal part of the name
     * rather than a `schema.table.column` separator. The canonical case is
     * ClickHouse's nested-array convention (`meta.key Array(String)`) where
     * `meta.key` is a single top-level column whose name contains a dot.
     */
    protected function quoteLiteral(string $identifier): string
    {
        if ($identifier === '*') {
            return '*';
        }

        if (\preg_match('/[\x00-\x1f\x7f]/', $identifier) === 1) {
            throw new ValidationException('Identifier contains control character');
        }

        return $this->wrapChar
            . \str_replace($this->wrapChar, $this->wrapChar . $this->wrapChar, $identifier)
            . $this->wrapChar;
    }
}
