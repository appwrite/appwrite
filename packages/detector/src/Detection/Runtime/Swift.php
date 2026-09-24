<?php

namespace Utopia\Detector\Detection\Runtime;

use Utopia\Detector\Detection\Runtime;

class Swift extends Runtime
{
    public function getName(): string
    {
        return 'swift';
    }

    /**
     * @return array<string>
     */
    public function getLanguages(): array
    {
        return ['Swift'];
    }

    public function getCommands(): string
    {
        return 'swift build';
    }

    /**
     * @return array<string>
     */
    public function getFileExtensions(): array
    {
        return ['swift', 'xcodeproj', 'xcworkspace'];
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return ['Package.swift', 'Podfile', 'project.pbxproj'];
    }

    public function getEntrypoint(): string
    {
        return 'main.swift';
    }
}
