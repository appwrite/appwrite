<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Detector\Detection;

use Utopia\Detector\Detection;

abstract class Mobile extends Detection
{
    public const PLATFORM_ANDROID = 'android';

    public const PLATFORM_IOS = 'ios';

    abstract public function getName(): string;

    /**
     * @return array<string> Platforms the project builds apps for.
     */
    abstract public function getPlatforms(): array;

    /**
     * @return array<string> File names that identify the project.
     */
    public function getFiles(): array
    {
    }

    /**
     * @return array<string> File or directory extensions that identify the project.
     */
    public function getFileExtensions(): array
    {
    }

    /**
     * @return array<string> Runtime dependencies that identify the project.
     */
    public function getPackages(): array
    {
    }

    /**
     * @return array<string> Gradle plugin ids that identify the project.
     */
    public function getPlugins(): array
    {
    }
}
