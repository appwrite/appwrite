<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Detector\Detector;

use Utopia\Detector\Detection\Framework as FrameworkDetection;
use Utopia\Detector\Detector;

class Framework extends Detector
{
    public const INPUT_FILE = 'file';
    public const INPUT_PACKAGES = 'packages';

    private const DEPENDENCY_SECTIONS = [
        'dependencies',
        'devDependencies',
        'peerDependencies',
        'optionalDependencies',
        'dev_dependencies',
        'dependency_overrides',
    ];

    /**
     * @var array<FrameworkDetection>
     */
    protected array $options = [];

    protected string $packager = 'pnpm';

    public function __construct(string $packager = 'pnpm')
    {
    }

    public function addInput(string $content, string $type = ''): self
    {
    }

    /**
     * Performs framework detection based on input patterns.
     *
     * @return FrameworkDetection|null The detected framework or null if no match is found.
     */
    public function detect(): ?FrameworkDetection
    {
    }

    /**
     * Reads declared dependency names out of a `package.json` or a `pubspec.yaml`.
     *
     * @return array<string>
     */
    protected function dependencies(string $manifest): array
    {
    }
}
