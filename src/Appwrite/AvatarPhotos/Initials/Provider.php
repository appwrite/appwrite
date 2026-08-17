<?php

namespace Appwrite\AvatarPhotos\Initials;

use Imagick;
use ImagickDraw;
use ImagickPixel;

/**
 * Initials provider.
 *
 * Generates a coloured square with the user's initials — identical logic to
 * the standalone GET /v1/avatars/initials endpoint, but exposed as a plain
 * PHP class so the photo-resolution chain can call it without going through
 * HTTP.
 */
class Provider
{
    private string $appRoot;

    /** Colour palette — same as the Initials endpoint. */
    private array $themes = [
        ['background' => '#FD366E'], // Pink
        ['background' => '#FE9567'], // Orange
        ['background' => '#7C67FE'], // Purple
        ['background' => '#68A3FE'], // Blue
        ['background' => '#85DBD8'], // Mint
    ];

    public function __construct(string $appRoot)
    {
        $this->appRoot = $appRoot;
    }

    /**
     * Generate an initials avatar image.
     *
     * @param string $name        Full name or email to derive initials from.
     *                            When empty or blank, returns null so the caller
     *                            moves on to the next (static fallback) provider.
     * @param int    $width       Canvas width in pixels.
     * @param int    $height      Canvas height in pixels.
     * @param string $background  Optional hex colour (without #). Empty = auto.
     * @return string|null        Raw PNG bytes, or null when no name/email is available.
     */
    public function get(string $name, int $width = 500, int $height = 500, string $background = ''): ?string
    {
        if (empty(\trim($name))) {
            return null;
        }

        $words = \explode(' ', \strtoupper($name));
        // Fallback: split on underscores when there is no space
        $words = (\count($words) === 1) ? \explode('_', \strtoupper($name)) : $words;

        $initials = '';
        $code = 0;

        foreach ($words as $key => $w) {
            if (\ctype_alnum($w[0] ?? '')) {
                $initials .= $w[0];
                $code += \ord($w[0]);

                if ($key === 1) {
                    break;
                }
            }
        }

        // If we still have no printable initials, bail out so the static
        // fallback can be used instead.
        if (empty($initials)) {
            return null;
        }

        $rand = (int) \substr((string) $code, -1);
        $rand = ($rand > \count($this->themes) - 1) ? $rand % \count($this->themes) : $rand;

        $bg = (!empty($background)) ? '#' . \ltrim($background, '#') : $this->themes[$rand]['background'];

        $image = new Imagick();
        $punch = new Imagick();
        $draw  = new ImagickDraw();

        $fontSize = \min($width, $height) / 2;

        $punch->newImage($width, $height, 'transparent');

        $fontPath = $this->appRoot . '/app/assets/fonts/inter-v8-latin-regular.woff2';
        $draw->setFont($fontPath);
        $image->setFont($fontPath);

        $draw->setFillColor(new ImagickPixel('black'));
        $draw->setFontSize($fontSize);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $draw->annotation($width / 1.97, ($height / 2) + ($fontSize / 3), $initials);

        $punch->drawImage($draw);
        $punch->negateImage(true, Imagick::CHANNEL_ALPHA);

        $image->newImage($width, $height, $bg);
        $image->setImageFormat('png');
        $image->compositeImage($punch, Imagick::COMPOSITE_COPYOPACITY, 0, 0);

        return $image->getImageBlob();
    }
}
