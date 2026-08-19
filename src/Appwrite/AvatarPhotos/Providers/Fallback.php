<?php

namespace Appwrite\AvatarPhotos\Providers;

use Appwrite\AvatarPhotos\Photo;
use Utopia\Database\Document;

/**
 * Static fallback provider.
 *
 * Returns a minimal built-in avatar image when all other providers have
 * failed.  The image is a simple SVG rendered to a PNG-compatible byte
 * string; designers can swap it out later for a brand-aligned asset
 * without changing any surrounding logic.
 *
 * The SVG depicts a neutral silhouette on an Appwrite-pink background —
 * similar to what "mystery man" (mp) looks like on Gravatar, but ours.
 *
 * This provider needs nothing from the user and never returns null, so it is
 * always safe to place last in the chain.
 */
class Fallback extends Photo
{
    public function getName(): string
    {
        return 'fallback';
    }

    public function supports(Document $user): bool
    {
        return true;
    }

    public function get(Document $user, int $width, int $height, string $rating): ?string
    {
        $width = $width > 0 ? $width : 256;
        $height = $height > 0 ? $height : 256;

        // Generate an SVG silhouette and rasterise it with Imagick so the
        // caller always receives a PNG regardless of whether SVG support is
        // available in the client.
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
                // Fall through to raw SVG on Imagick failure.
            }
        }

        // Last resort: return the SVG bytes directly.  The HTTP action sets
        // the content-type header to image/png, so this branch should be
        // considered a degraded path.
        return $svg;
    }

    /**
     * Build the SVG source for the fallback silhouette.
     */
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
