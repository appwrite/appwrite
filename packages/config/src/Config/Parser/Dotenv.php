<?php

namespace Utopia\Config\Parser;

use ReflectionAttribute;
use Utopia\Config\Attribute\Key;
use Utopia\Config\Exception\Parse;
use Utopia\Config\Parser;

class Dotenv extends Parser
{
    /**
     * @var array<string> $truthyValues
     */
    protected array $truthyValues = ['1', 'true', 'yes', 'on', 'enabled'];

    /**
     * @var array<string> $falsyValues
     */
    protected array $falsyValues = ['0', 'false', 'no', 'off', 'disabled'];

    /**
     * @return string|bool|null
     */
    protected function convertValue(string $value, string $type): mixed
    {
        if ($type === 'bool') {
            if (\in_array(strtolower($value), $this->truthyValues)) {
                return true;
            }
            if (\in_array(strtolower($value), $this->falsyValues)) {
                return false;
            }
        }

        return $value;
    }

    /**
     * Resolve the raw right-hand side of a dotenv line into its value.
     *
     * A quoted value keeps a `#` inside its quotes instead of having it treated
     * as a comment; an unquoted value has any inline comment (from the first
     * `#`) stripped.
     */
    protected function parseValue(string $raw): string
    {
        $raw = trim($raw);

        if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) {
            return $this->parseQuoted($raw, $raw[0]);
        }

        $hash = strpos($raw, '#');
        if ($hash !== false) {
            $raw = substr($raw, 0, $hash);
        }

        return trim($raw);
    }

    /**
     * Read a quoted value, scanning to the matching closing quote. Double quotes
     * honour backslash escapes (so `\"` stays in the value); single quotes are
     * literal. Anything other than an inline comment after the closing quote, or
     * a missing closing quote, is malformed.
     *
     * @throws Parse
     */
    protected function parseQuoted(string $raw, string $quote): string
    {
        $value = '';
        $length = \strlen($raw);

        for ($i = 1; $i < $length; $i++) {
            $char = $raw[$i];

            // In double quotes, only \" and \\ are escapes; every other
            // backslash is literal (e.g. a Windows path like C:\tmp).
            if ($char === '\\' && $quote === '"' && $i + 1 < $length
                && ($raw[$i + 1] === '"' || $raw[$i + 1] === '\\')) {
                $value .= $raw[$i + 1];
                $i++;
                continue;
            }

            if ($char === $quote) {
                // Only whitespace or an inline comment may follow the close
                $rest = trim(substr($raw, $i + 1));
                if ($rest !== '' && $rest[0] !== '#') {
                    throw new Parse('Config file is not a valid dotenv file.');
                }

                return $value;
            }

            $value .= $char;
        }

        throw new Parse('Config file is not a valid dotenv file.');
    }

    /**
     * @param \ReflectionClass<covariant object>|null $reflection
     * @return array<string, mixed>
     */
    public function parse(mixed $contents, ?\ReflectionClass $reflection = null): array
    {
        if (!\is_string($contents)) {
            throw new Parse('Contents must be a string.');
        }

        if ($contents === '' || $contents === '0') {
            return [];
        }

        $config = [];

        $lines = explode("\n", $contents);
        foreach ($lines as $line) {
            $line = trim($line);
            // Blank line or whole-line comment
            if ($line === '') {
                continue;
            }
            if ($line[0] === '#') {
                continue;
            }

            // Split into KEY=value
            $parts = explode('=', $line, 2);

            // A line without '=' is malformed; fail fast rather than load it
            // as a key with an empty value.
            if (\count($parts) < 2) {
                throw new Parse('Config file is not a valid dotenv file.');
            }

            $name = trim($parts[0]);
            $value = $this->parseValue($parts[1]);

            // Missing name likely means bad syntax
            if ($name === '' || $name === '0') {
                throw new Parse('Config file is not a valid dotenv file.');
            }

            // Smart type-casting
            if ($reflection instanceof \ReflectionClass) {
                $reflectionProperty = null;
                foreach ($reflection->getProperties() as $property) {
                    foreach ($property->getAttributes(Key::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                        $key = $attribute->newInstance();
                        if ($key->name === $name) {
                            $reflectionProperty = $property;
                            break 2;
                        }
                    }
                }
                if ($reflectionProperty !== null) {
                    $reflectionType = $reflectionProperty->getType();
                    if ($reflectionType !== null && method_exists($reflectionType, 'getName')) {
                        $value = $this->convertValue($value, $reflectionType->getName());
                    }
                }
            }

            if (\is_string($value) && strtolower($value) === 'null') {
                $value = null;
            }

            $config[$name] = $value;
        }

        return $config;
    }
}
