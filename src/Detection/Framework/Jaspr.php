<?php

namespace Utopia\Detector\Detection\Framework;

use Utopia\Detector\Detection\Framework;

class Jaspr extends Framework
{
    public function getName(): string
    {
        return 'jaspr';
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
        return ['jaspr'];
    }

    public function getInstallCommand(): string
    {
        return 'dart pub get';
    }

    public function getBuildCommand(): string
    {
        return 'dart run jaspr_cli:jaspr build';
    }

    public function getOutputDirectory(): string
    {
        return './build/jaspr';
    }

    /**
     * @return array<string>
     */
    public function getConfigFiles(): array
    {
        return ['pubspec.yaml'];
    }

    public function getAdapter(string $configContent): string
    {
        $stripped = \preg_replace('/^\s*#[^\n]*/m', '', $configContent) ?? $configContent;

        if (\preg_match('/^jaspr\s*:(?<body>.*?)(?=^\S|\z)/ms', $stripped, $matches) !== 1) {
            return '';
        }

        if (\preg_match('/^\s+mode\s*:\s*[\x27\x22]?(?<mode>[a-z-]+)/m', $matches['body'], $mode) !== 1) {
            return 'static';
        }

        return $mode['mode'] === 'static' ? 'static' : 'ssr';
    }
}
