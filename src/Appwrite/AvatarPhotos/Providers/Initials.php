<?php

namespace Appwrite\AvatarPhotos\Providers;

use Appwrite\AvatarPhotos\Photo;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use Utopia\Database\Document;

/**
 * Initials provider.
 *
 * Generates a coloured square with the user's initials. This is the single
 * implementation behind both the photo-resolution chain and the standalone
 * GET /v1/avatars/initials endpoint — the endpoint renders an arbitrary label
 * through render(), the chain resolves the label off the user first.
 */
class Initials extends Photo
{
    /** Font used to render the initials, relative to the project root. */
    private const FONT_PATH = '/app/assets/fonts/inter-v8-latin-regular.woff2';

    /** Edge length used when the caller does not ask for a size. */
    private const DEFAULT_SIZE = 500;

    /** Colour palette — a theme is picked from the initials themselves. */
    private array $themes = [
        ['background' => '#FD366E'], // Pink
        ['background' => '#FE9567'], // Orange
        ['background' => '#7C67FE'], // Purple
        ['background' => '#68A3FE'], // Blue
        ['background' => '#85DBD8'], // Mint
    ];

    public function __construct(
        private readonly string $background = '',
    ) {
    }

    public function getName(): string
    {
        return 'initials';
    }

    public function supports(Document $user): bool
    {
        return !empty(\trim($this->getLabel($user)));
    }

    public function get(Document $user, int $width, int $height, string $rating): ?string
    {
        if (!\extension_loaded('imagick')) {
            return null;
        }

        $name = $this->getLabel($user);

        // Nothing printable to draw — bail out so the static fallback can be
        // used instead. The standalone endpoint has no such fallback and keeps
        // rendering a plain coloured square, which is why this check lives
        // here and not in render().
        if (empty($this->getInitials($name))) {
            return null;
        }

        return $this->render($name, $width, $height);
    }

    /**
     * Render the initials of an arbitrary label as a PNG.
     *
     * @param string $name   Label to derive the initials from.
     * @param int    $width  Output width in pixels; defaults when not positive.
     * @param int    $height Output height in pixels; defaults when not positive.
     * @return string Raw PNG bytes.
     */
    public function render(string $name, int $width, int $height): string
    {
        $initials = $this->getInitials($name);

        $width = $width > 0 ? $width : self::DEFAULT_SIZE;
        $height = $height > 0 ? $height : self::DEFAULT_SIZE;

        $bg = !empty($this->background)
            ? '#' . \ltrim($this->background, '#')
            : $this->getTheme($initials);

        $image = new Imagick();
        $punch = new Imagick();
        $draw  = new ImagickDraw();

        $fontSize = \min($width, $height) / 2;

        $punch->newImage($width, $height, 'transparent');

        // Providers live at src/Appwrite/AvatarPhotos/Providers, four levels
        // below the project root — same walk-up as Avatars\Http\Action.
        $fontPath = \dirname(__DIR__, 4) . self::FONT_PATH;
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

    /**
     * First letter of the first two words, skipping words that do not start
     * with an alphanumeric character. Underscores stand in for spaces when the
     * label has none.
     */
    private function getInitials(string $name): string
    {
        $words = \explode(' ', \strtoupper($name));
        // Fallback: split on underscores when there is no space
        $words = (\count($words) === 1) ? \explode('_', \strtoupper($name)) : $words;

        $initials = '';

        foreach ($words as $key => $w) {
            if (\ctype_alnum($w[0] ?? '')) {
                $initials .= $w[0];

                if ($key === 1) {
                    break;
                }
            }
        }

        return $initials;
    }

    /**
     * Background colour for a set of initials. Derived from the initials so the
     * same label always gets the same colour.
     */
    private function getTheme(string $initials): string
    {
        $code = 0;

        foreach (\str_split($initials) as $char) {
            $code += \ord($char);
        }

        $rand = (int) \substr((string) $code, -1);
        $rand = ($rand > \count($this->themes) - 1) ? $rand % \count($this->themes) : $rand;

        return $this->themes[$rand]['background'];
    }

    /**
     * Text the initials are derived from — the display name, falling back to
     * the email address when the user has not set one.
     */
    private function getLabel(Document $user): string
    {
        $name = $user->getAttribute('name', '');

        return !empty($name) ? $name : $user->getAttribute('email', '');
    }
}
