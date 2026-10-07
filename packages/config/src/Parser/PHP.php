<?php

declare(strict_types=1);

namespace Utopia\Config\Parser;

use Utopia\Config\Exception\Parse;
use Utopia\Config\Parser;

class PHP extends Parser
{
    /**
     * @param \ReflectionClass<covariant object>|null $reflection
     * @return array<string, mixed>
     */
    public function parse(mixed $contents, ?\ReflectionClass $reflection = null): array
    {
        if (!\is_string($contents)) {
            throw new Parse('Contents must be a string.');
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'utopia_config_');
        if ($tempPath === false) {
            throw new Parse('Failed to create temporary file for PHP config.');
        }

        if (file_put_contents($tempPath, $contents) === false) {
            throw new Parse('Failed to write PHP config to temporary file.');
        }

        try {
            $contents = include $tempPath;
        } catch (\Throwable $e) {
            throw new Parse('Failed to parse PHP config file: ' . $e->getMessage(), $e->getCode(), $e);
        } finally {
            unlink($tempPath);
        }

        if (!\is_array($contents)) {
            throw new Parse('PHP config file must return an array.');
        }

        return $contents;
    }
}
