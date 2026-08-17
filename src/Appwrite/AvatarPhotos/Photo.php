<?php

namespace Appwrite\AvatarPhotos;

use Appwrite\AvatarPhotos\Gravatar\Provider as GravatarProvider;
use Appwrite\AvatarPhotos\Libavatar\Provider as LibavatarProvider;
use Appwrite\AvatarPhotos\Initials\Provider as InitialsProvider;
use Appwrite\AvatarPhotos\Static\Provider as StaticProvider;
use Utopia\Image\Image;

/**
 * Photo resolver.
 *
 * Walks through avatar providers in priority order and returns the first
 * successful result as processed PNG/WEBP/JPEG bytes.
 *
 * Priority chain:
 *  1. OAuth2 session photo  — TODO: implement in a follow-up PR
 *  2. Gravatar              — requires email
 *  3. Libravatar            — requires email
 *  4. Initials              — requires name or email
 *  5. Static fallback       — always succeeds
 */
class Photo
{
    private GravatarProvider $gravatar;
    private LibavatarProvider $libavatar;
    private InitialsProvider $initials;
    private StaticProvider $static;

    public function __construct(string $appRoot)
    {
        $this->gravatar  = new GravatarProvider();
        $this->libavatar = new LibavatarProvider();
        $this->initials  = new InitialsProvider($appRoot);
        $this->static    = new StaticProvider();
    }

    /**
     * Resolve and return the best available avatar for the given user data.
     *
     * @param string $email      User's email address (may be empty).
     * @param string $name       User's display name (may be empty).
     * @param int    $width      Desired output width in pixels.
     * @param int    $height     Desired output height in pixels.
     * @param int    $quality    Output quality 0–100.
     * @param string $output     Output format: 'png' | 'jpg' | 'webp'.
     * @param string $rating     Gravatar/Libravatar rating: 'g' | 'pg' | 'r' | 'x'.
     * @param string $background Optional hex background for the Initials provider.
     * @return string            Raw image bytes in the requested $output format.
     */
    public function resolve(
        string $email,
        string $name,
        int $width = 256,
        int $height = 256,
        int $quality = 100,
        string $output = 'png',
        string $rating = 'g',
        string $background = '',
    ): string {
        // -------------------------------------------------------------------
        // Priority 1: OAuth2 session photo
        // TODO: Resolve profile photo from the user's active OAuth2 token.
        //       Each OAuth2 provider (Google, GitHub, …) exposes a profile-
        //       picture URL.  Fetch it here and short-circuit the chain.
        //       Track in: https://github.com/appwrite/appwrite/issues/TODO
        // -------------------------------------------------------------------

        // -------------------------------------------------------------------
        // Priority 2: Gravatar
        // -------------------------------------------------------------------
        if (!empty($email)) {
            $raw = $this->gravatar->get($email, $rating);
            if ($raw !== null) {
                return $this->process($raw, $width, $height, $quality, $output);
            }
        }

        // -------------------------------------------------------------------
        // Priority 3: Libravatar
        // -------------------------------------------------------------------
        if (!empty($email)) {
            $raw = $this->libavatar->get($email, $rating);
            if ($raw !== null) {
                return $this->process($raw, $width, $height, $quality, $output);
            }
        }

        // -------------------------------------------------------------------
        // Priority 4: Initials
        // Prefer name; fall back to email prefix when name is absent.
        // -------------------------------------------------------------------
        $initialsInput = !empty($name) ? $name : $email;
        if (!empty($initialsInput)) {
            $raw = $this->initials->get($initialsInput, $width, $height, $background);
            if ($raw !== null) {
                // Initials PNG is already sized correctly — just re-encode if
                // a different format or quality was requested.
                return $this->process($raw, $width, $height, $quality, $output);
            }
        }

        // -------------------------------------------------------------------
        // Priority 5: Static fallback — always succeeds
        // -------------------------------------------------------------------
        $raw = $this->static->get($width, $height);
        return $this->process($raw, $width, $height, $quality, $output);
    }

    /**
     * Resize and re-encode raw image bytes.
     */
    private function process(string $raw, int $width, int $height, int $quality, string $output): string
    {
        if (!\extension_loaded('imagick') || empty($raw)) {
            return $raw;
        }

        try {
            $image = new Image($raw);
            if ($width > 0 || $height > 0) {
                $image->crop(
                    $width > 0 ? $width : 256,
                    $height > 0 ? $height : 256,
                );
            }
            return $image->output($output, $quality);
        } catch (\Throwable) {
            return $raw;
        }
    }
}
