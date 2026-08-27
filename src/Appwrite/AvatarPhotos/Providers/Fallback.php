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
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}">
  <rect width="{$width}" height="{$height}" fill="#FD366E"/>
  <!-- Head -->
  <circle cx="50%" cy="38%" r="22%" fill="rgba(255,255,255,0.85)"/>
  <!-- Body / shoulder shape -->
  <ellipse cx="50%" cy="90%" rx="35%" ry="30%" fill="rgba(255,255,255,0.85)"/>
</svg>
SVG;
    }
}
