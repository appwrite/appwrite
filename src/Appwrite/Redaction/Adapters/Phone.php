<?php

namespace Appwrite\Redaction\Adapters;

use Appwrite\Redaction\Exceptions\Redaction;

/**
 * Masks all digits except (optionally) a detected international country code prefix and the last N digits.
 * Keeps the original non-digit formatting (spaces, dashes, parentheses) intact.
 *
 * Example: "+65 9876 5432" with keepCountryCode=true, visibleSuffixDigits=4 => "+65 **** 5432"
 */
final class Phone implements Adapter
{
    private bool $keepCountryCode = true; // if leading "+" + up to 3 digits exist, keep them
    private int $visibleSuffixDigits = 4; // how many digits at the end remain visible?

    public function setKeepCountryCode(bool $value): self
    {
        $this->keepCountryCode = $value;
        return $this;
    }

    public function setVisibleSuffixDigits(int $value): self
    {
        $this->visibleSuffixDigits = max(0, $value);
        return $this;
    }

    public function redact(string $phone): string
    {
        if ($phone === '') {
            throw new Redaction('Empty phone value for redaction.');
        }

        // Gather all digits
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            // Nothing to redact if there are no digits
            return $phone;
        }

        // Detect "+<1-3 digits>" prefix as country code
        $hasPlus = str_starts_with($phone, '+');
        $countryDigits = '';
        if ($hasPlus) {
            // Extract up to the first 3 digits after '+'
            $i = 1;
            while ($i < strlen($phone) && ctype_digit($phone[$i]) && strlen($countryDigits) < 3) {
                $countryDigits .= $phone[$i];
                $i++;
            }
        }

        $keepPrefixCount = ($this->keepCountryCode && $hasPlus) ? strlen($countryDigits) : 0;
        $totalDigits = strlen($digits);
        $keepSuffixCount = min($this->visibleSuffixDigits, max(0, $totalDigits - $keepPrefixCount));

        // Build masked digit stream
        $maskedDigits = '';
        for ($idx = 0; $idx < $totalDigits; $idx++) {
            $isPrefix = $idx < $keepPrefixCount;
            $isSuffix = $idx >= ($totalDigits - $keepSuffixCount);
            $maskedDigits .= ($isPrefix || $isSuffix) ? $digits[$idx] : '*';
        }

        // Merge back with the original formatting
        $result = '';
        $pos = 0;
        for ($j = 0; $j < strlen($phone); $j++) {
            $ch = $phone[$j];
            if (ctype_digit($ch)) {
                $result .= $maskedDigits[$pos++];
            } else {
                $result .= $ch;
            }
        }

        return $result;
    }
}
