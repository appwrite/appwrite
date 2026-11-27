<?php

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
     */
    public function addInput(string $content, string $type = ''): self
    {
        if (! \in_array($type, self::INPUTS, true)) {
            throw new \InvalidArgumentException("Invalid input type '{$type}'");
        }

        parent::addInput($content, $type);

        return $this;
    }

    /**
     * A cross-platform project embeds native Android and iOS projects, so the
     * matching option that covers the most platforms wins. Ties keep the option
     * added first.
     */
    public function detect(): ?MobileDetection
    {
        $files = [];
        $extensions = [];
        $dependencies = [];
        $statements = [];

        foreach ($this->inputs as $input) {
            switch ($input['type']) {
                case self::INPUT_FILE:
                    $files[] = \basename($input['content']);
                    $extensions[] = \pathinfo($input['content'], PATHINFO_EXTENSION);
                    break;
                case self::INPUT_PACKAGES:
                    \array_push($dependencies, ...$this->dependencies($input['content']));
                    break;
                case self::INPUT_GRADLE:
                    \array_push($statements, ...$this->statements($input['content']));
                    break;
            }
        }

        $detection = null;

        foreach ($this->options as $option) {
            $matches = \array_intersect($option->getFiles(), $files) !== []
                || \array_intersect($option->getFileExtensions(), $extensions) !== []
                || \array_intersect($option->getPackages(), $dependencies) !== []
                || $this->declares($option->getPlugins(), $statements);

            if ($matches && ($detection === null || \count($option->getPlatforms()) > \count($detection->getPlatforms()))) {
                $detection = $option;
            }
        }

        return $detection;
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
        if (\trim($manifest) === '') {
            return [];
        }

        $parsed = \json_decode($manifest, true);

        if (! \is_array($parsed)) {
            $parsed = @\yaml_parse($manifest);
        }

        $dependencies = \is_array($parsed) ? ($parsed['dependencies'] ?? null) : null;

        if (! \is_array($dependencies)) {
            return [];
        }

        return \array_map('strval', \array_keys($dependencies));
    }

    /**
     * Keeps the lines of a Gradle script or version catalog that are not comments.
     *
     * @return array<string>
     */
    protected function statements(string $script): array
    {
        $statements = [];
        $commented = false;

        foreach (\explode("\n", $script) as $line) {
            $line = \trim($line);

            if ($commented || \str_starts_with($line, '/*')) {
                $commented = ! \str_contains($line, '*/');
                continue;
            }

            if ($line === '' || $this->isLineComment($line)) {
                continue;
            }

            $statements[] = $line;
        }

        return $statements;
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
        foreach ($plugins as $plugin) {
            foreach (self::QUOTES as $quote) {
                $literal = $quote . $plugin . $quote;

                foreach ($statements as $statement) {
                    if (\str_contains($statement, $literal)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function isLineComment(string $line): bool
    {
        foreach (self::LINE_COMMENTS as $marker) {
            if (\str_starts_with($line, $marker)) {
                return true;
            }
        }

        return false;
    }
}
