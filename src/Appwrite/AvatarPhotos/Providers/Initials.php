<?php

namespace Appwrite\AvatarPhotos\Providers;

use Appwrite\AvatarPhotos\Photo;
use Utopia\Database\Document;
use Utopia\Image\Image;

/**
 * Generates a grey square with the initials of the user's name.
 */
class Initials extends Photo
{
    private const FONT_PATH = '/app/assets/fonts/inter-v8-latin-regular.ttf';
    private const DEFAULT_SIZE = 500;

    public function __construct(
        private readonly string $background = '',
    ) {
    }

    public function getName(): string
    {
        return 'initials';
    }

    public function supports(Document $profile): bool
    {
        return \trim($profile->getAttribute('name', '')) !== '';
    }

    public function get(Document $profile, int $width, int $height, string $rating): ?string
    {
        $name = $profile->getAttribute('name', '');

        if ($this->getInitials($name) === '') {
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

        $background = $this->background !== ''
            ? '#' . \ltrim($this->background, '#')
            : self::SURFACE;

        // Longer initials shrink to keep fitting the square
        $fontSize = \min($width, $height) * 1.6 / (2 + \mb_strlen($initials, 'UTF-8'));

        return Image::create($width, $height, $background)
            ->text($initials, $this->getFont(), $fontSize, self::FIGURE, $width / 1.97, ($height / 2) + ($fontSize / 3))
            ->output('png') ?? '';
    }

    /**
     * First letter of every word, uppercased, capped at four — mapped onto
     * characters the bundled font can draw.
     */
    private function getInitials(string $name): string
    {
        // Uppercased before the split so a case change that alters length,
        // like 'ß' to 'SS', still yields one letter per word
        $words = \array_slice($this->getWords(\mb_strtoupper($name, 'UTF-8')), 0, 4);

        $initials = \implode('', \array_map(fn (string $word) => \mb_substr($word, 0, 1, 'UTF-8'), $words));

        return $this->getDrawable($initials);
    }

    /**
     * Words that start with a letter or digit, split on spaces — or on
     * underscores when the label has none. A leading @, as in a handle, is
     * ignored.
     *
     * @return string[]
     */
    private function getWords(string $name): array
    {
        $words = \explode(' ', \ltrim(\trim($name), '@'));

        // Fallback: split on underscores when there is no space
        $words = (\count($words) === 1) ? \explode('_', $words[0]) : $words;

        return \array_values(\array_filter(
            $words,
            fn (string $word) => \preg_match('/^[\p{L}\p{N}]/u', $word) === 1,
        ));
    }

    /**
     * Map letters onto characters the bundled font can draw (a missing glyph
     * draws as blank space, not as a replacement box). Characters
     * without a glyph are transliterated to Latin (Ł → L, А → A) and dropped
     * when no Latin form is available, so letters never render as a blank
     * square.
     */
    private function getDrawable(string $letters): string
    {
        $drawable = '';

        foreach (\mb_str_split($letters, 1, 'UTF-8') as $char) {
            if (!Image::hasGlyph($this->getFont(), $char) && \function_exists('transliterator_transliterate')) {
                $latin = (string) \transliterator_transliterate('Any-Latin; Latin-ASCII', $char);
                $char = \mb_strtoupper(\mb_substr($latin, 0, 1, 'UTF-8'), 'UTF-8');
            }

            if ($char !== '' && Image::hasGlyph($this->getFont(), $char)) {
                $drawable .= $char;
            }
        }

        return $drawable;
    }

    private function getFont(): string
    {
        // Providers live at src/Appwrite/AvatarPhotos/Providers
        return \dirname(__DIR__, 4) . self::FONT_PATH;
    }
}
