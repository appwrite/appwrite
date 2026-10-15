<?php

namespace Utopia\Detector\Detection\Runtime;

use Utopia\Detector\Detection\Runtime;

class CPP extends Runtime
{
    public function getName(): string
    {
        return 'cpp';
    }

    /**
     * @return array<string>
     */
    public function getLanguages(): array
    {
        return ['C++'];
    }

    public function getCommands(): string
    {
        return 'g++ -o main.cpp && ./output';
    }

    /**
     * @return array<string>
     */
    public function getFileExtensions(): array
    {
        return ['cpp', 'h', 'hpp', 'cxx', 'cc'];
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return ['main.cpp', 'Solution', 'CMakeLists.txt'];
    }

    public function getEntrypoint(): string
    {
        return '';
    }
}
