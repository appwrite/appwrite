<?php

namespace Utopia\Detector\Detection\Runtime;

use Utopia\Detector\Detection\Runtime;

class Bun extends Runtime
{
    public function getName(): string
    {
        return 'bun';
    }

    /**
     * @return array<string>
     */
    public function getLanguages(): array
    {
        return ['JavaScript', 'TypeScript'];
    }

    public function getCommands(): string
    {
        return 'bun install && bun build';
    }

    /**
     * @return array<string>
     */
    public function getFileExtensions(): array
    {
        return ['ts', 'tsx', 'js', 'jsx'];
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return ['bun.lockb'];
    }

    public function getEntrypoint(): string
    {
        return 'main.ts';
    }
}
