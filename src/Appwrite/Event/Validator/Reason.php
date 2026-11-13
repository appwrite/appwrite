<?php

namespace Appwrite\Event\Validator;

use Utopia\Validator\Validator;

/**
 * The reason a caller gave for a request (X-Appwrite-Reason), after percent-decoding.
 *
 * One line of UTF-8 text. Control characters are refused, so a reason cannot add lines or terminal escapes
 * to an audit entry, and so are the bidirectional embedding, override and isolate controls, so it cannot
 * reorder how it reads. Those are the characters compilers flag since Trojan Source (CVE-2021-42574);
 * right-to-left text and the left-to-right and right-to-left marks need none of them.
 */
class Reason extends Validator
{
    public function __construct(private readonly int $length)
    {
    }

    public function getDescription(): string
    {
        return 'Value must be one line of UTF-8 text of at most ' . $this->length . ' characters, without control characters.';
    }

    public function isValid($value): bool
    {
        if (!\is_string($value) || !\mb_check_encoding($value, 'UTF-8') || \mb_strlen($value) > $this->length) {
            return false;
        }

        foreach (\mb_str_split($value) as $character) {
            $codepoint = \mb_ord($character);

            $refused = $codepoint < 0x20                           // C0 controls, line feed and tab among them
                || ($codepoint >= 0x7F && $codepoint <= 0x9F)      // DEL and C1 controls
                || $codepoint === 0x2028 || $codepoint === 0x2029  // line and paragraph separators
                || ($codepoint >= 0x202A && $codepoint <= 0x202E)  // bidirectional embeddings and overrides
                || ($codepoint >= 0x2066 && $codepoint <= 0x2069); // bidirectional isolates

            if ($refused) {
                return false;
            }
        }

        return true;
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
