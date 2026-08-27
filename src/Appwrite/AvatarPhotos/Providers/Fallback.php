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

        // Drawn rather than rasterised from the SVG below: ImageMagick's
        // built-in SVG renderer ignores strokes, and the mark is an outline,
        // so reading the markup back would yield a bare square.
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
     * Mirrors buildSvg() — the same 24x24 lucide geometry, in Imagick
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
        $draw->setFillOpacity(0);
        $draw->setStrokeColor(new \ImagickPixel(self::FIGURE));
        $draw->setStrokeWidth(2 * $scale);
        $draw->setStrokeLineCap(\Imagick::LINECAP_ROUND);
        $draw->setStrokeLineJoin(\Imagick::LINEJOIN_ROUND);

        // Shoulders: M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2
        $draw->pathStart();
        $draw->pathMoveToAbsolute($x + 19 * $scale, $y + 21 * $scale);
        $draw->pathLineToRelative(0, -2 * $scale);
        $draw->pathEllipticArcRelative(4 * $scale, 4 * $scale, 0, false, false, -4 * $scale, -4 * $scale);
        $draw->pathLineToHorizontalAbsolute($x + 9 * $scale);
        $draw->pathEllipticArcRelative(4 * $scale, 4 * $scale, 0, false, false, -4 * $scale, 4 * $scale);
        $draw->pathLineToRelative(0, 2 * $scale);
        $draw->pathFinish();

        // Head: cx 12, cy 7, r 4
        $draw->circle(
            $x + 12 * $scale,
            $y + 7 * $scale,
            $x + 16 * $scale,
            $y + 7 * $scale,
        );

        $image->drawImage($draw);

        return $image->getImageBlob();
    }

    private function buildSvg(int $width, int $height): string
    {
        $surface = self::SURFACE;
        $figure = self::FIGURE;

        // The person mark the console draws for a user without a photo:
        // lucide 'user' (ISC), authored on a 24x24 grid. Scaled to just over
        // half the avatar and centred, so it sits like an icon rather than
        // filling the tile. Percentage units are avoided on purpose — an SVG
        // percentage radius resolves against the diagonal, which distorts the
        // mark as soon as width and height differ.
        $box = \min($width, $height) * 0.55;
        $scale = $box / 24;

        $x = ($width - $box) / 2;
        $y = ($height - $box) / 2;

        $transform = \sprintf('translate(%.4F %.4F) scale(%.6F)', $x, $y, $scale);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}">
  <rect width="{$width}" height="{$height}" fill="{$surface}"/>
  <g transform="{$transform}" fill="none" stroke="{$figure}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <!-- Shoulders -->
    <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
    <!-- Head -->
    <circle cx="12" cy="7" r="4"/>
  </g>
</svg>
SVG;
    }
}
