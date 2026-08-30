<?php

namespace Appwrite\AvatarPhotos\Providers;

use Appwrite\AvatarPhotos\Photo;
use Utopia\Database\Document;

/**
 * Static fallback provider, a minimal built-in avatar placeholder
 */
class Fallback extends Photo
{
    public function getName(): string
    {
        return 'fallback';
    }

    public function supports(Document $profile): bool
    {
        return true;
    }

    public function get(Document $profile, int $width, int $height, string $rating): ?string
    {
        $width = $width > 0 ? $width : 256;
        $height = $height > 0 ? $height : 256;

        // Drawn rather than rasterised from the SVG below: ImageMagick's SVG
        // decoder can be disabled entirely by its security policy, and where
        // it is available its delegates vary by build. Draw primitives work
        // everywhere Imagick does.
        if (\extension_loaded('imagick')) {
            try {
                return $this->render($width, $height);
            } catch (\Throwable) {
            }
        }

        // Last resort, with no Imagick to draw with: the SVG bytes directly
        return $this->buildSvg($width, $height);
    }

    /**
     * Draw the person mark centred on the neutral surface.
     *
     * Mirrors buildSvg() — the same 24x24 heroicons geometry, in Imagick
     * primitives. Keep the two in step.
     */
    private function render(int $width, int $height): string
    {
        $box = \min($width, $height) * 0.55;
        $scale = $box / 24;

        $x = ($width - $box) / 2;
        $y = ($height - $box) / 2;

        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel(self::SURFACE));
        $image->setImageFormat('png');

        $draw = new \ImagickDraw();
        $draw->setFillColor(new \ImagickPixel(self::FIGURE));
        $draw->setStrokeOpacity(0);

        // Head: M7.5 6a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0Z — a circle
        // at (12, 6) with radius 4.5
        $draw->circle(
            $x + 12 * $scale,
            $y + 6 * $scale,
            $x + 16.5 * $scale,
            $y + 6 * $scale,
        );

        // Shoulders: M3.751 20.105a8.25 8.25 0 0 1 16.498 0 .75.75 0 0
        // 1-.437.695A18.683 18.683 0 0 1 12 22.5c-2.786 0-5.433-.608
        // -7.812-1.7a.75.75 0 0 1-.437-.695Z
        $draw->pathStart();
        $draw->pathMoveToAbsolute($x + 3.751 * $scale, $y + 20.105 * $scale);
        $draw->pathEllipticArcRelative(8.25 * $scale, 8.25 * $scale, 0, false, true, 16.498 * $scale, 0);
        $draw->pathEllipticArcRelative(0.75 * $scale, 0.75 * $scale, 0, false, true, -0.437 * $scale, 0.695 * $scale);
        $draw->pathEllipticArcAbsolute(18.683 * $scale, 18.683 * $scale, 0, false, true, $x + 12 * $scale, $y + 22.5 * $scale);
        $draw->pathCurveToRelative(-2.786 * $scale, 0, -5.433 * $scale, -0.608 * $scale, -7.812 * $scale, -1.7 * $scale);
        $draw->pathEllipticArcRelative(0.75 * $scale, 0.75 * $scale, 0, false, true, -0.437 * $scale, -0.695 * $scale);
        $draw->pathClose();
        $draw->pathFinish();

        $image->drawImage($draw);

        return $image->getImageBlob();
    }

    private function buildSvg(int $width, int $height): string
    {
        $surface = self::SURFACE;
        $figure = self::FIGURE;

        // The person mark the console draws for a user without a photo:
        // heroicons 'user' solid (MIT), authored on a 24x24 grid. Scaled to
        // just over half the avatar and centred, so it sits like an icon
        // rather than filling the tile. Percentage units are avoided on
        // purpose — an SVG percentage radius resolves against the diagonal,
        // which distorts the mark as soon as width and height differ.
        $box = \min($width, $height) * 0.55;
        $scale = $box / 24;

        $x = ($width - $box) / 2;
        $y = ($height - $box) / 2;

        $transform = \sprintf('translate(%.4F %.4F) scale(%.6F)', $x, $y, $scale);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}">
  <rect width="{$width}" height="{$height}" fill="{$surface}"/>
  <path transform="{$transform}" fill="{$figure}" fill-rule="evenodd" clip-rule="evenodd" d="M7.5 6a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0ZM3.751 20.105a8.25 8.25 0 0 1 16.498 0 .75.75 0 0 1-.437.695A18.683 18.683 0 0 1 12 22.5c-2.786 0-5.433-.608-7.812-1.7a.75.75 0 0 1-.437-.695Z"/>
</svg>
SVG;
    }
}
