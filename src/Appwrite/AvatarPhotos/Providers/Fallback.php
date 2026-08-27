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

        $svg = $this->buildSvg($width, $height);

        if (\extension_loaded('imagick')) {
            try {
                $imagick = new \Imagick();
                $imagick->setBackgroundColor(new \ImagickPixel('transparent'));
                $imagick->readImageBlob($svg);
                $imagick->setImageFormat('png');
                $imagick->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1);
                return $imagick->getImageBlob();
            } catch (\Throwable) {
            }
        }

        // Last resort: return the SVG bytes directly
        return $svg;
    }

    private function buildSvg(int $width, int $height): string
    {
        // The person mark: heroicons 'user' solid (MIT), authored on a 24x24
        // grid — https://heroicons.com. Scaled to just over half the avatar
        // and centred, so it sits like an icon rather than filling the tile.
        $box = \min($width, $height) * 0.6;
        $scale = $box / 24;

        $x = ($width - $box) / 2;
        $y = ($height - $box) / 2;

        $transform = \sprintf('translate(%.4F %.4F) scale(%.6F)', $x, $y, $scale);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}">
  <rect width="{$width}" height="{$height}" fill="#FD366E"/>
  <path transform="{$transform}" fill="rgba(255,255,255,0.85)" fill-rule="evenodd" clip-rule="evenodd" d="M7.5 6a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0ZM3.751 20.105a8.25 8.25 0 0 1 16.498 0 .75.75 0 0 1-.437.695A18.683 18.683 0 0 1 12 22.5c-2.786 0-5.433-.608-7.812-1.7a.75.75 0 0 1-.437-.695Z"/>
</svg>
SVG;
    }
}
