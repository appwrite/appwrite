<?php

namespace Utopia\Detector\Detection\Framework;

use Utopia\Detector\Detection\Framework;

class Flutter extends Framework
{
    public function getName(): string
    {
        return 'flutter';
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return ['pubspec.yaml', 'pubspec.lock'];
    }

    /**
     * @return array<string>
     */
    public function getPackages(): array
    {
        return ['flutter'];
    }

    public function getInstallCommand(): string
    {
        return '';
    }

    public function getBuildCommand(): string
    {
        return 'flutter build web';
    }

    public function getOutputDirectory(): string
    {
        return './build/web';
    }
}
