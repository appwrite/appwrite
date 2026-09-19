<?php

namespace Utopia\Detector\Detector;

use Utopia\Detector\Detection\Framework as FrameworkDetection;
use Utopia\Detector\Detection\Framework\Astro;
use Utopia\Detector\Detector;
use Utopia\Detector\Yaml;

class Framework extends Detector
{
    public const INPUT_FILE = 'file';
    public const INPUT_PACKAGES = 'packages';

    /**
     * @var array<FrameworkDetection>
     */
    protected array $options = [];

    protected string $packager = 'pnpm';

    public function __construct(string $packager = 'pnpm')
    {
        parent::__construct();
        $this->packager = $packager;
    }

    public function addInput(string $content, string $type = ''): self
    {
        if ($type !== self::INPUT_FILE && $type !== self::INPUT_PACKAGES) {
            throw new \InvalidArgumentException("Invalid input type '{$type}'");
        }

        parent::addInput($content, $type);

        return $this;
    }

    /**
     * Performs framework detection based on input patterns.
     *
     * @return FrameworkDetection|null The detected framework or null if no match is found.
     */
    public function detect(): ?FrameworkDetection
    {
        $files = array_filter($this->inputs, fn ($input) => $input['type'] === self::INPUT_FILE);
        $files = array_map(fn ($input) => $input['content'], $files);

        $packages = array_filter($this->inputs, fn ($input) => $input['type'] === self::INPUT_PACKAGES);
        $packages = array_map(fn ($input) => $input['content'], $packages);

        // List of frameworks with count of matches
        $frameworkMatches = [];

        // List of frameworks with count of parents
        // Helps decide framework priority when multiple frameworks got same matches count
        // (framework with least parents wins)
        $fameworkParents = [];

        foreach ($this->options as $detector) {
            $frameworkMatches[$detector->getName()] = 0;
            $fameworkParents[$detector->getName()] = 0;
        }

        foreach ($this->options as $detector) {
            // Check package-based detection
            foreach ($packages as $manifest) {
                foreach ($detector->getPackages() as $packageNeeded) {
                    if ($this->hasPackage($manifest, $packageNeeded)) {
                        $frameworkMatches[$detector->getName()] += 1;
                    }
                }
            }

            // Check path-based detection
            $matches = array_intersect($detector->getFiles(), $files);
            $frameworkMatches[$detector->getName()] += \count($matches);

            // Figure out how many classes detector extends using native PHP
            $parent = \get_parent_class($detector);
            while ($parent !== false) {
                $fameworkParents[$detector->getName()] += 1;
                $parent = \get_parent_class($parent);
            }
        }

        // Filter out frameworks with 0 matches
        $frameworkMatches = array_filter($frameworkMatches, fn ($count) => $count > 0);

        if (\count($frameworkMatches) <= 0) {
            return null;
        }

        // Sort for framework with most matches to be first
        arsort($frameworkMatches);

        // Filter out non-max matches
        $highestMatch = $frameworkMatches[\array_key_first($frameworkMatches)];
        $frameworkMatches = array_filter($frameworkMatches, fn ($count) => $count == $highestMatch);

        if (\count($frameworkMatches) === 1) {
            $bestFramework = \array_key_first($frameworkMatches);
        } else {
            $bestFrameworks = \array_keys($frameworkMatches);
            usort($bestFrameworks, fn ($a, $b) => $fameworkParents[$a] <=> $fameworkParents[$b]);

            $bestFramework = $bestFrameworks[0];

            // Trick to prevent Astro (library-agnostic framework) from detecting everywhere
            $astro = (new Astro())->getName();
            if (
                $bestFramework === $astro &&
                // and 2nd detection equally good as Astro
                $frameworkMatches[$astro] == $frameworkMatches[$bestFrameworks[1]]
            ) {
                $bestFramework = $bestFrameworks[1];
            }
        }

        foreach ($this->options as $detector) {
            if ($detector->getName() === $bestFramework) {
                $detector->setPackager($this->packager);
                return $detector;
            }
        }

        return null;
    }

    /**
     * Matches a dependency key in JSON manifests, where keys are always double
     * quoted, and in YAML ones, where a key may be unquoted, quoted, or inside
     * a flow mapping such as `dependencies: {jaspr: ^0.23.0}`.
     */
    protected function hasPackage(string $manifest, string $package): bool
    {
        if (\str_contains($manifest, '"'.$package.'"')) {
            return true;
        }

        return Yaml::hasKey($manifest, $package);
    }
}
