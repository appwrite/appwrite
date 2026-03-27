<?php

namespace Appwrite\Filter;

class Name implements Filter
{
    /**
     * Homoglyph mapping: visually similar characters mapped to their Latin equivalents.
     */
    private const HOMOGLYPHS = [
        // Cyrillic
        'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c',
        'у' => 'y', 'х' => 'x', 'А' => 'A', 'В' => 'B', 'Е' => 'E',
        'К' => 'K', 'М' => 'M', 'Н' => 'H', 'О' => 'O', 'Р' => 'P',
        'С' => 'C', 'Т' => 'T', 'У' => 'Y', 'Х' => 'X',
        // Greek
        'α' => 'a', 'ο' => 'o', 'ε' => 'e', 'Α' => 'A', 'Β' => 'B',
        'Ε' => 'E', 'Η' => 'H', 'Ι' => 'I', 'Κ' => 'K', 'Μ' => 'M',
        'Ν' => 'N', 'Ο' => 'O', 'Ρ' => 'P', 'Τ' => 'T', 'Χ' => 'X',
        'Ζ' => 'Z', 'ν' => 'v', 'τ' => 't',
    ];

    /**
     * Scam trigger phrases to block (checked against lowercased input).
     */
    private const SCAM_PHRASES = [
        'giveaway', 'free crypto', 'click here', 'contact me on',
        'send me dm', 'discount', 'promo code', 'limited offer',
        'whatsapp', 'telegram', 'discord', 'free money', 'act now',
        'congratulations you won', 'claim your prize', 'send me a message',
        'dm me', 'text me', 'call me', 'wire transfer',
    ];

    private const MAX_LENGTH = 32;

    public function apply(mixed $input): mixed
    {
        if (!\is_string($input)) {
            return $input;
        }

        $input = $this->stripZeroWidthChars($input);
        $input = $this->normalizeUnicode($input);
        $input = $this->normalizeHomoglyphs($input);
        $input = $this->removeEmojis($input);
        $input = $this->removeUrls($input);
        $input = $this->removeSocialHandles($input);
        $input = $this->stripPhoneNumbers($input);
        $input = $this->removeEmailAddresses($input);
        $input = $this->stripHtmlTags($input);
        $input = $this->blockScamPhrases($input);
        $input = $this->collapseWhitespace($input);
        $input = $this->cutToMaxLength($input);

        return $input;
    }

    /**
     * Cut to max length.
     */
    private function cutToMaxLength(string $input): string
    {
        return \mb_substr($input, 0, self::MAX_LENGTH);
    }

    /**
     * Remove emoji characters.
     */
    private function removeEmojis(string $input): string
    {
        // Covers most emoji ranges including emoticons, symbols, dingbats, flags, etc.
        $pattern = '/[\x{1F600}-\x{1F64F}' .  // Emoticons
            '\x{1F300}-\x{1F5FF}' .             // Misc symbols & pictographs
            '\x{1F680}-\x{1F6FF}' .             // Transport & map
            '\x{1F1E0}-\x{1F1FF}' .             // Flags
            '\x{2600}-\x{26FF}' .               // Misc symbols
            '\x{2700}-\x{27BF}' .               // Dingbats
            '\x{FE00}-\x{FE0F}' .               // Variation selectors
            '\x{1F900}-\x{1F9FF}' .             // Supplemental symbols
            '\x{1FA00}-\x{1FA6F}' .             // Chess symbols
            '\x{1FA70}-\x{1FAFF}' .             // Symbols extended-A
            '\x{200D}' .                         // Zero-width joiner (used in emoji sequences)
            '\x{20E3}' .                         // Combining enclosing keycap
            ']+/u';

        return \preg_replace($pattern, '', $input) ?? $input;
    }

    /**
     * Remove URLs (http://, https://, www., and common TLD patterns).
     */
    private function removeUrls(string $input): string
    {
        // Match http(s):// URLs
        $input = \preg_replace('#https?://[^\s]+#iu', '', $input) ?? $input;

        // Match www. URLs
        $input = \preg_replace('#www\.[^\s]+#iu', '', $input) ?? $input;

        // Match bare domain-like patterns (e.g. "example.com", "scam.link")
        $input = \preg_replace('#\b[a-zA-Z0-9\-]+\.(com|org|net|io|co|me|info|link|xyz|click|top|ru|cn|tk|ml|ga|cf|gq|pw|bid|win)\b#iu', '', $input) ?? $input;

        return $input;
    }

    /**
     * Remove social media handles (@username, #hashtag).
     */
    private function removeSocialHandles(string $input): string
    {
        // Remove @mentions
        $input = \preg_replace('/@[\w.]{1,30}/u', '', $input) ?? $input;

        // Remove #hashtags
        $input = \preg_replace('/#[\w]{1,50}/u', '', $input) ?? $input;

        return $input;
    }

    /**
     * Strip phone numbers and phone-like patterns.
     */
    private function stripPhoneNumbers(string $input): string
    {
        // Match international format: +1 234 567 8900, +44-20-7946-0958, etc.
        $input = \preg_replace('/\+?\d[\d\s\-\.\(\)]{6,}\d/u', '', $input) ?? $input;

        return $input;
    }

    /**
     * Remove email addresses.
     */
    private function removeEmailAddresses(string $input): string
    {
        $input = \preg_replace('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/u', '', $input) ?? $input;

        return $input;
    }

    /**
     * Strip HTML tags and elements.
     */
    private function stripHtmlTags(string $input): string
    {
        return \strip_tags($input);
    }

    /**
     * Strip zero-width and invisible Unicode characters.
     */
    private function stripZeroWidthChars(string $input): string
    {
        $pattern = '/[\x{200B}-\x{200F}' .  // Zero-width space, non-joiner, joiner, LTR/RTL marks
            '\x{2028}-\x{202F}' .             // Line/paragraph separators, embedding controls
            '\x{2060}-\x{2064}' .             // Word joiner, invisible separators
            '\x{FEFF}' .                       // BOM / zero-width no-break space
            '\x{00AD}' .                       // Soft hyphen
            '\x{034F}' .                       // Combining grapheme joiner
            '\x{061C}' .                       // Arabic letter mark
            '\x{180E}' .                       // Mongolian vowel separator
            ']+/u';

        return \preg_replace($pattern, '', $input) ?? $input;
    }

    /**
     * Normalize Unicode using NFKC normalization.
     * Converts compatibility characters to their canonical equivalents.
     */
    private function normalizeUnicode(string $input): string
    {
        if (\function_exists('normalizer_normalize')) {
            return \Normalizer::normalize($input, \Normalizer::FORM_KC) ?: $input;
        }

        return $input;
    }

    /**
     * Normalize homoglyph/lookalike characters to Latin equivalents.
     */
    private function normalizeHomoglyphs(string $input): string
    {
        return \str_replace(
            \array_keys(self::HOMOGLYPHS),
            \array_values(self::HOMOGLYPHS),
            $input
        );
    }

    /**
     * Block known scam trigger phrases by removing them.
     */
    private function blockScamPhrases(string $input): string
    {
        $lower = \mb_strtolower($input);

        foreach (self::SCAM_PHRASES as $phrase) {
            $pos = \mb_strpos($lower, $phrase);
            while ($pos !== false) {
                $len = \mb_strlen($phrase);
                $input = \mb_substr($input, 0, $pos) . \mb_substr($input, $pos + $len);
                $lower = \mb_substr($lower, 0, $pos) . \mb_substr($lower, $pos + $len);
                $pos = \mb_strpos($lower, $phrase, $pos);
            }
        }

        return $input;
    }

    /**
     * Collapse repeated whitespace and trim.
     */
    private function collapseWhitespace(string $input): string
    {
        $input = \preg_replace('/\s+/u', ' ', $input) ?? $input;

        return \trim($input);
    }
}
