<?php

namespace Appwrite\Filter;

class Name implements Filter
{
    private const MAX_LENGTH = 32;

    public function apply(mixed $input): mixed
    {
        if (!\is_string($input)) {
            return $input;
        }

        // Remove HTML tags
        $input = \strip_tags($input);

        $words = \explode(' ', $input);

        // Remove emails
        $words = \array_filter($words, fn (string $word) => !\str_contains($word, '@') || !\str_contains($word, '.'));

        // Remove URLs
        $words = \array_filter($words, fn (string $word) => !\str_starts_with($word, '://') && !\str_starts_with(\strtolower($word), 'www.'));

        // Remove phone numbers
        $words = \array_filter($words, function (string $word) {
            $digitCount = 0;
            for ($i = 0, $len = \strlen($word); $i < $len; $i++) {
                if (\ctype_digit($word[$i])) {
                    $digitCount++;
                }
            }
            return $digitCount < 7;
        });

        $input = \implode(' ', $words);

        return \mb_substr($input, 0, self::MAX_LENGTH);
    }
}
