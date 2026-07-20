<?php

namespace Appwrite\Deployment;

use Utopia\Detector\Detection\Rendering\SSR;
use Utopia\Detector\Detection\Rendering\XStatic;
use Utopia\Detector\Detector\Rendering;

/**
 * Detects a site's rendering mode (ssr or static) from its build-output
 * file listing.
 */
final class Detection
{
    /**
     * @param array<string> $files
     */
    public static function rendering(string $framework, array $files): object
    {
        $files = \array_map(\trim(...), $files);
        $files = \array_filter($files);
        $files = \array_map(fn ($file) => \str_starts_with($file, './') ? \substr($file, 2) : $file, $files);

        $detector = new Rendering($framework);
        foreach ($files as $file) {
            $detector->addInput($file);
        }

        return $detector
            ->addOption(new SSR())
            ->addOption(new XStatic())
            ->detect();
    }
}
