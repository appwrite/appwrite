<?php

namespace Appwrite\GraphQL;

use GraphQL\Error\Error;

final class Formatter
{
    /**
     * @param list<Error> $errors
     * @param callable(Error): array<string, mixed> $formatter
     * @return list<array<string, mixed>>
     */
    public static function errors(array $errors, callable $formatter): array
    {
        return \array_map(
            static function (Error $error) use ($formatter): array {
                $formatted = $formatter($error);
                $extensions = $error->getExtensions();
                if ($extensions !== null) {
                    $formatted['extensions'] = \array_replace(
                        $formatted['extensions'] ?? [],
                        $extensions,
                    );
                }

                return $formatted;
            },
            $errors,
        );
    }
}
