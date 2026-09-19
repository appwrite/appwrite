<?php

namespace Utopia\Detector;

/**
 * Dependency manifests, parsed by the JSON and YAML extensions rather than by
 * hand: `package.json` is JSON, `pubspec.yaml` is YAML.
 */
final class Manifest
{
    private const DEPENDENCY_SECTIONS = [
        'dependencies',
        'devDependencies',
        'peerDependencies',
        'optionalDependencies',
        'dev_dependencies',
        'dependency_overrides',
    ];

    /**
     * @return array<mixed>
     */
    public static function parse(string $content): array
    {
        $manifest = \json_decode($content, true);

        if (! \is_array($manifest)) {
            $manifest = @\yaml_parse($content);
        }

        return \is_array($manifest) ? $manifest : [];
    }

    /**
     * @return array<string>
     */
    public static function dependencies(string $content): array
    {
        $manifest = self::parse($content);
        $dependencies = [];

        foreach (self::DEPENDENCY_SECTIONS as $section) {
            $packages = $manifest[$section] ?? null;

            if (! \is_array($packages)) {
                continue;
            }

            foreach (\array_keys($packages) as $package) {
                $dependencies[] = (string) $package;
            }
        }

        return $dependencies;
    }
}
