<?php

namespace Utopia\Detector\Detection\Runtime;

use Utopia\Detector\Detection\Runtime;

class Python extends Runtime
{
    public function getName(): string
    {
        return 'python';
    }

    /**
     * @return array<string>
     */
    public function getLanguages(): array
    {
        return ['Python'];
    }

    public function getCommands(): string
    {
        return 'pip install';
    }

    /**
     * @return array<string>
     */
    public function getFileExtensions(): array
    {
        return ['py'];
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return ['requirements.txt', 'setup.py'];
    }

    public function getEntrypoint(): string
    {
        return 'main.py';
    }
}
