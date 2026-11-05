<?php

namespace Utopia\Detector\Detection\Runtime;

use Utopia\Detector\Detection\Runtime;

class Dart extends Runtime
{
    public function getName(): string
    {
        return 'dart';
    }

    /**
     * @return array<string>
     */
    public function getLanguages(): array
    {
        return ['Dart'];
    }

    public function getCommands(): string
    {
        return 'dart pub get';
    }

    /**
     * @return array<string>
     */
    public function getFileExtensions(): array
    {
        return ['dart'];
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return ['pubspec.yaml', 'pubspec.lock'];
    }

    public function getEntrypoint(): string
    {
        return 'main.dart';
    }
}
