<?php

namespace Appwrite\Vcs\Validator;

use Utopia\Validator;

class CommitSkipPatterns extends Validator
{
    public function __construct(private readonly array $patterns)
    {
    }

    /**
     * Returns false (skip deployment) when the commit message contains any of the
     * configured skip directives.
     * Returns true (proceed) when no patterns are configured or none match.
     */
    public function isValid($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $patterns = $this->normalizePatterns($this->patterns);
        if (empty($patterns)) {
            return true;
        }

        foreach ($this->extractDirectives($value) as $directive) {
            if (isset($patterns[$directive])) {
                return false;
            }
        }

        return true;
    }

    public function getDescription(): string
    {
        return 'Commit message must not contain any of the configured skip patterns.';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }

    /**
     * @param array<mixed> $patterns
     * @return array<string, true>
     */
    private function normalizePatterns(array $patterns): array
    {
        $normalized = [];

        foreach ($patterns as $pattern) {
            if (!\is_string($pattern)) {
                continue;
            }

            $pattern = $this->normalizeDirective($pattern);
            if ($pattern === '') {
                continue;
            }

            $normalized[$pattern] = true;
        }

        return $normalized;
    }

    /**
     * @return array<string>
     */
    private function extractDirectives(string $message): array
    {
        $directives = [];

        if (\preg_match_all('/\[[^\]\r\n]+\]/u', $message, $matches) > 0) {
            foreach ($matches[0] as $match) {
                $directives[] = $this->normalizeDirective($match);
            }
        }

        foreach (\preg_split("/\r\n|\n|\r/", $message) ?: [] as $line) {
            $line = \trim($line);
            if ($line === '' || !\str_contains($line, ':')) {
                continue;
            }

            $directives[] = $this->normalizeDirective($line);
        }

        return \array_values(\array_filter(\array_unique($directives)));
    }

    private function normalizeDirective(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        $value = (string) \preg_replace('/\s+/u', ' ', $value);
        return \mb_strtolower($value);
    }
}
