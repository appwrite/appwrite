<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Detector\Detector;

use Utopia\Detector\Detection\Mobile as MobileDetection;
use Utopia\Detector\Detector;

class Mobile extends Detector
{
    public const INPUT_FILE = 'file';
    public const INPUT_PACKAGES = 'packages';
    public const INPUT_GRADLE = 'gradle';

    private const INPUTS = [
        self::INPUT_FILE,
        self::INPUT_PACKAGES,
        self::INPUT_GRADLE,
    ];

    private const QUOTES = ['\'', '"'];

    private const LINE_COMMENTS = ['//', '#'];

    /**
     * @var array<MobileDetection>
     */
    protected array $options = [];

    /**
     * @param string $content A file name or path, a `package.json` or `pubspec.yaml` manifest, or a Gradle script or version catalog
     * @param string $type One of the INPUT_* constants
     *
     * @throws \InvalidArgumentException When `$type` is not one of the INPUT_* constants.
     */
    public function addInput(string $content, string $type = ''): self
    {
    }

    /**
     * A cross-platform project embeds native Android and iOS projects, so the
     * matching option that covers the most platforms wins. Ties keep the option
     * added first.
     */
    public function detect(): ?MobileDetection
    {
    }

    /**
     * Reads the runtime dependency names of a `package.json` or a `pubspec.yaml`.
     * Development and peer dependencies are ignored: a library lists the framework
     * there, an app ships it.
     *
     * @return array<string>
     */
    protected function dependencies(string $manifest): array
    {
    }

    /**
     * Keeps the lines of a Gradle script or version catalog that are not comments.
     *
     * @return array<string>
     */
    protected function statements(string $script): array
    {
    }

    /**
     * A plugin id counts wherever it is quoted: applied (`apply plugin:`, `id(...)`),
     * declared with `apply false` in a root build, or listed in a version catalog.
     *
     * @param array<string> $plugins
     * @param array<string> $statements
     */
    protected function declares(array $plugins, array $statements): bool
    {
    }
}
