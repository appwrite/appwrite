<?php

namespace Utopia\Query\AST;

final class Grammar
{
    private const string IDENTIFIER_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_';

    private const string WHITESPACE = " \t\n\v\f\r";

    private const string TYPE_ARGUMENT_CHARACTERS = self::IDENTIFIER_CHARACTERS . ',' . self::WHITESPACE;

    public static function isIdentifier(string $value): bool
    {
        return $value !== ''
            && !\ctype_digit($value[0])
            && \strspn($value, self::IDENTIFIER_CHARACTERS) === \strlen($value);
    }

    public static function isType(string $value): bool
    {
        $open = \strpos($value, '(');

        if ($open === false) {
            return self::isWords($value);
        }

        if (!\str_ends_with($value, ')')) {
            return false;
        }

        $arguments = \substr($value, $open + 1, -1);

        return $arguments !== ''
            && \strspn($arguments, self::TYPE_ARGUMENT_CHARACTERS) === \strlen($arguments)
            && self::isWords(\rtrim(\substr($value, 0, $open), self::WHITESPACE));
    }

    private static function isWords(string $value): bool
    {
        $words = \explode(' ', \strtr($value, self::WHITESPACE, \str_repeat(' ', \strlen(self::WHITESPACE))));

        if ($words[0] === '' || $words[\array_key_last($words)] === '') {
            return false;
        }

        return \array_all($words, fn (string $word): bool => $word === '' || self::isIdentifier($word));
    }
}
