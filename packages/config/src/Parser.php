<?php

declare(strict_types=1);

namespace Utopia\Config;

use Utopia\Config\Exception\Parse;

abstract class Parser
{
    /**
     * @param \ReflectionClass<covariant object>|null $reflection Useful for loosely typed parsers (like Dotenv) to cast smartly to correct types
     * @return array<string, mixed>
     * @throws Parse
     */
    abstract public function parse(mixed $contents, ?\ReflectionClass $reflection = null): array;

    /**
     * A config must be a key/value map. Valid JSON/YAML may also be a scalar
     * (e.g. `123`) or a sequence (e.g. `[a, b]`); reject those so callers never
     * receive a non-map. An empty array is allowed (an empty object and an empty
     * list are indistinguishable once decoded, and both are harmless).
     *
     * Limitation: a top-level map whose keys are all sequential integers from 0
     * (e.g. `{"0": ...}`) is rejected, because it decodes to a list-shaped PHP
     * array that is indistinguishable from a real sequence — yaml_parse() in
     * particular gives identical output for `0: x` and `- x`. Config keys are
     * names, so this degenerate shape is not supported.
     *
     * @return array<string, mixed>
     * @throws Parse
     */
    protected function requireMap(mixed $config, string $message): array
    {
        if (!\is_array($config) || (\count($config) > 0 && array_is_list($config))) {
            throw new Parse($message);
        }

        return $config;
    }
}
