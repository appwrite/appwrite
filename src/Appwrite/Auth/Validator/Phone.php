<?php

namespace Appwrite\Auth\Validator;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use Utopia\Validator;

/**
 * Phone.
 *
 * Enhanced phone number validator with comprehensive validation features.
 * Supports E.164 format validation, country code validation,
 * phone number formatting, type detection, and region checking.
 */
class Phone extends Validator
{
    protected bool $allowEmpty;
    protected PhoneNumberUtil $helper;
    protected array $allowedCountries;
    protected array $allowedTypes;
    protected bool $strictValidation;
    protected string $defaultRegion;
    protected bool $allowExtensions;
    protected bool $validateCarrier;

    /**
     * Constructor.
     *
     * @param bool $allowEmpty Allow empty phone numbers
     * @param array $allowedCountries List of allowed country codes (ISO 3166-1 alpha-2)
     * @param array $allowedTypes List of allowed phone types (mobile, landline, toll_free, etc.)
     * @param bool $strictValidation Enable strict validation with carrier info
     * @param string $defaultRegion Default region code for parsing
     * @param bool $allowExtensions Allow phone extensions
     * @param bool $validateCarrier Validate carrier information
     */
    public function __construct(
        bool $allowEmpty = false,
        array $allowedCountries = [],
        array $allowedTypes = [],
        bool $strictValidation = false,
        string $defaultRegion = 'US',
        bool $allowExtensions = false,
        bool $validateCarrier = false
    ) {
        $this->allowEmpty = $allowEmpty;
        $this->helper = PhoneNumberUtil::getInstance();
        $this->allowedCountries = $allowedCountries;
        $this->allowedTypes = $allowedTypes;
        $this->strictValidation = $strictValidation;
        $this->defaultRegion = $defaultRegion;
        $this->allowExtensions = $allowExtensions;
        $this->validateCarrier = $validateCarrier;
    }

    /**
     * Get Description.
     *
     * Returns enhanced validator description based on current configuration.
     *
     * @return string
     */
    public function getDescription(): string
    {
        $description = 'Phone number must be in E.164 format (starts with '+' and has 7-15 digits)';
        
        if (!empty($this->allowedCountries)) {
            $description .= ' and be from allowed countries: ' . implode(', ', $this->allowedCountries);
        }
        
        if (!empty($this->allowedTypes)) {
            $description .= ' and be of allowed types: ' . implode(', ', $this->allowedTypes);
        }
        
        if ($this->allowExtensions) {
            $description .= ' (extensions allowed)';
        }
        
        if ($this->strictValidation) {
            $description .= ' (strict validation enabled)';
        }
        
        return $description . '.';
    }

    /**
     * Is valid.
     *
     * Enhanced phone number validation with multiple security checks.
     *
     * @param mixed $value
     *
     * @return bool
     */
    public function isValid($value): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        if ($this->allowEmpty && \strlen($value) === 0) {
            return true;
        }

        // Basic E.164 format validation
        if (!$this->isValidE164Format($value)) {
            return false;
        }

        try {
            // $this->helper->parse($value);
            $phoneNumber = $this->helper->parse($value, $this->defaultRegion);
        } catch (NumberParseException $e) {
            return false;
        }

        // return !!\preg_match('/^\+[1-9]\d{6,14}$/', $value);
        // Validate phone number is possible
        if (!$this->helper->isPossibleNumber($phoneNumber)) {
            return false;
        }

        // Validate phone number is valid for region
        if (!$this->helper->isValidNumber($phoneNumber)) {
            return false;
        }

        // Country code validation
        if (!empty($this->allowedCountries) && !$this->isAllowedCountry($phoneNumber)) {
            return false;
        }

        // Phone type validation
        if (!empty($this->allowedTypes) && !$this->isAllowedType($phoneNumber)) {
            return false;
        }

        // Strict validation checks
        if ($this->strictValidation && !$this->passesStrictValidation($phoneNumber)) {
            return false;
        }

        return true;
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     *
     * @return bool
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_STRING;
    }

    /**
     * Check if phone number is in valid E.164 format.
     *
     * @param string $phone
     * @return bool
     */
    protected function isValidE164Format(string $phone): bool
    {
        // Remove any whitespace
        $phone = preg_replace('/\s+/', '', $phone);
        
        // Check E.164 format: + followed by 7-15 digits
        return !!\preg_match('/^\+[1-9]\d{6,14}$/', $phone);
    }

    /**
     * Check if phone number is from allowed country.
     *
     * @param \libphonenumber\PhoneNumber $phoneNumber
     * @return bool
     */
    protected function isAllowedCountry(\libphonenumber\PhoneNumber $phoneNumber): bool
    {
        $countryCode = $this->helper->getRegionCodeForNumber($phoneNumber);
        return in_array($countryCode, $this->allowedCountries, true);
    }

    /**
     * Check if phone number type is allowed.
     *
     * @param \libphonenumber\PhoneNumber $phoneNumber
     * @return bool
     */
    protected function isAllowedType(\libphonenumber\PhoneNumber $phoneNumber): bool
    {
        $type = $this->helper->getNumberType($phoneNumber);
        
        $typeMap = [
            PhoneNumberType::MOBILE => 'mobile',
            PhoneNumberType::FIXED_LINE => 'landline',
            PhoneNumberType::TOLL_FREE => 'toll_free',
            PhoneNumberType::PREMIUM_RATE => 'premium_rate',
            PhoneNumberType::SHARED_COST => 'shared_cost',
            PhoneNumberType::VOIP => 'voip',
            PhoneNumberType::PERSONAL_NUMBER => 'personal',
            PhoneNumberType::PAGER => 'pager',
            PhoneNumberType::UAN => 'uan',
            PhoneNumberType::VOICEMAIL => 'voicemail',
            PhoneNumberType::UNKNOWN => 'unknown'
        ];
        
        $phoneType = $typeMap[$type] ?? 'unknown';
        return in_array($phoneType, $this->allowedTypes, true);
    }

    /**
     * Perform strict validation checks.
     *
     * @param \libphonenumber\PhoneNumber $phoneNumber
     * @return bool
     */
    protected function passesStrictValidation(\libphonenumber\PhoneNumber $phoneNumber): bool
    {
        // Check if number is not from a known invalid range
        if ($this->isInvalidRange($phoneNumber)) {
            return false;
        }
        
        // Check carrier validation if enabled
        if ($this->validateCarrier && !$this->hasValidCarrier($phoneNumber)) {
            return false;
        }
        
        return true;
    }

    /**
     * Check if phone number is from invalid range.
     *
     * @param \libphonenumber\PhoneNumber $phoneNumber
     * @return bool
     */
    protected function isInvalidRange(\libphonenumber\PhoneNumber $phoneNumber): bool
    {
        $nationalNumber = $phoneNumber->getNationalNumber();
        
        // Known invalid ranges (example ranges)
        $invalidRanges = [
            '5550100' => '5550199', // Fictional numbers
            '5550200' => '5550299', // Fictional numbers
        ];
        
        foreach ($invalidRanges as $start => $end) {
            if ($nationalNumber >= (int)$start && $nationalNumber <= (int)$end) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if phone number has valid carrier.
     *
     * @param \libphonenumber\PhoneNumber $phoneNumber
     * @return bool
     */
    protected function hasValidCarrier(\libphonenumber\PhoneNumber $phoneNumber): bool
    {
        // This would require carrier database integration
        // For now, return true as placeholder
        return true;
    }

    /**
     * Format phone number to E.164 format.
     *
     * @param string $phone
     * @return string|null Formatted phone number or null if invalid
     */
    public function formatToE164(string $phone): ?string
    {
        try {
            $phoneNumber = $this->helper->parse($phone, $this->defaultRegion);
            return $this->helper->format($phoneNumber, PhoneNumberFormat::E164);
        } catch (NumberParseException $e) {
            return null;
        }
    }

    /**
     * Format phone number for international display.
     *
     * @param string $phone
     * @return string|null Formatted phone number or null if invalid
     */
    public function formatForInternational(string $phone): ?string
    {
        try {
            $phoneNumber = $this->helper->parse($phone, $this->defaultRegion);
            return $this->helper->format($phoneNumber, PhoneNumberFormat::INTERNATIONAL);
        } catch (NumberParseException $e) {
            return null;
        }
    }

    /**
     * Format phone number for national display.
     *
     * @param string $phone
     * @return string|null Formatted phone number or null if invalid
     */
    public function formatForNational(string $phone): ?string
    {
        try {
            $phoneNumber = $this->helper->parse($phone, $this->defaultRegion);
            return $this->helper->format($phoneNumber, PhoneNumberFormat::NATIONAL);
        } catch (NumberParseException $e) {
            return null;
        }
    }

    /**
     * Get phone number type.
     *
     * @param string $phone
     * @return string|null Phone type (mobile, landline, etc.) or null if invalid
     */
    public function getPhoneNumberType(string $phone): ?string
    {
        try {
            $phoneNumber = $this->helper->parse($phone, $this->defaultRegion);
            $type = $this->helper->getNumberType($phoneNumber);
            
            $typeMap = [
                PhoneNumberType::MOBILE => 'mobile',
                PhoneNumberType::FIXED_LINE => 'landline',
                PhoneNumberType::TOLL_FREE => 'toll_free',
                PhoneNumberType::PREMIUM_RATE => 'premium_rate',
                PhoneNumberType::SHARED_COST => 'shared_cost',
                PhoneNumberType::VOIP => 'voip',
                PhoneNumberType::PERSONAL_NUMBER => 'personal',
                PhoneNumberType::PAGER => 'pager',
                PhoneNumberType::UAN => 'uan',
                PhoneNumberType::VOICEMAIL => 'voicemail',
                PhoneNumberType::UNKNOWN => 'unknown'
            ];
            
            return $typeMap[$type] ?? null;
        } catch (NumberParseException $e) {
            return null;
        }
    }

    /**
     * Get country code for phone number.
     *
     * @param string $phone
     * @return string|null Country code or null if invalid
     */
    public function getCountryCode(string $phone): ?string
    {
        try {
            $phoneNumber = $this->helper->parse($phone, $this->defaultRegion);
            return $this->helper->getRegionCodeForNumber($phoneNumber);
        } catch (NumberParseException $e) {
            return null;
        }
    }

    /**
     * Get detailed phone number information.
     *
     * @param string $phone
     * @return array Detailed phone information or empty array if invalid
     */
    public function getPhoneInfo(string $phone): array
    {
        try {
            $phoneNumber = $this->helper->parse($phone, $this->defaultRegion);
            
            return [
                'original' => $phone,
                'e164' => $this->helper->format($phoneNumber, PhoneNumberFormat::E164),
                'international' => $this->helper->format($phoneNumber, PhoneNumberFormat::INTERNATIONAL),
                'national' => $this->helper->format($phoneNumber, PhoneNumberFormat::NATIONAL),
                'country_code' => $phoneNumber->getCountryCode(),
                'region_code' => $this->helper->getRegionCodeForNumber($phoneNumber),
                'national_number' => $phoneNumber->getNationalNumber(),
                'type' => $this->getPhoneNumberType($phone),
                'is_valid' => $this->isValid($phone),
                'is_possible' => $this->helper->isPossibleNumber($phoneNumber),
                'timezone' => $this->getTimezoneForPhone($phoneNumber),
            ];
        } catch (NumberParseException $e) {
            return [
                'original' => $phone,
                'is_valid' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get timezone for phone number region.
     *
     * @param \libphonenumber\PhoneNumber $phoneNumber
     * @return string|null Timezone or null if not available
     */
    protected function getTimezoneForPhone(\libphonenumber\PhoneNumber $phoneNumber): ?string
    {
        $regionCode = $this->helper->getRegionCodeForNumber($phoneNumber);
        
        // Simple timezone mapping (would be enhanced with proper timezone database)
        $timezoneMap = [
            'US' => 'America/New_York',
            'GB' => 'Europe/London',
            'DE' => 'Europe/Berlin',
            'FR' => 'Europe/Paris',
            'JP' => 'Asia/Tokyo',
            'AU' => 'Australia/Sydney',
            'CA' => 'America/Toronto',
            'IN' => 'Asia/Kolkata',
            'CN' => 'Asia/Shanghai',
            'BR' => 'America/Sao_Paulo',
        ];
        
        return $timezoneMap[$regionCode] ?? null;
    }

    /**
     * Validate multiple phone numbers.
     *
     * @param array $phones Array of phone numbers
     * @return array Results with validity status for each phone
     */
    public function validateMultiple(array $phones): array
    {
        $results = [];
        
        foreach ($phones as $index => $phone) {
            $results[$index] = [
                'phone' => $phone,
                'is_valid' => $this->isValid($phone),
                'info' => $this->getPhoneInfo($phone),
            ];
        }
        
        return $results;
    }

    /**
     * Extract phone numbers from text.
     *
     * @param string $text Text to extract phone numbers from
     * @return array Array of found phone numbers
     */
    public function extractFromText(string $text): array
    {
        // Enhanced regex to find various phone formats
        $patterns = [
            // E.164 format
            '/\+?[1-9]\d{6,14}/',
            // International format with spaces, dashes, parentheses
            '/\+?\d{1,3}[-.\s]?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/',
            // US format
            '/\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/',
            // Simple digits
            '/\d{7,15}/',
        ];
        
        $phones = [];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[0] as $match) {
                    $cleanPhone = $this->formatToE164($match);
                    if ($cleanPhone && !in_array($cleanPhone, $phones)) {
                        $phones[] = $cleanPhone;
                    }
                }
            }
        }
        
        return $phones;
    }

    /**
     * Check if phone number is mobile.
     *
     * @param string $phone
     * @return bool
     */
    public function isMobile(string $phone): bool
    {
        return $this->getPhoneNumberType($phone) === 'mobile';
    }

    /**
     * Check if phone number is landline.
     *
     * @param string $phone
     * @return bool
     */
    public function isLandline(string $phone): bool
    {
        return $this->getPhoneNumberType($phone) === 'landline';
    }

    /**
     * Check if phone number is toll-free.
     *
     * @param string $phone
     * @return bool
     */
    public function isTollFree(string $phone): bool
    {
        return $this->getPhoneNumberType($phone) === 'toll_free';
    }

    /**
     * Get supported countries.
     *
     * @return array List of supported country codes
     */
    public function getSupportedCountries(): array
    {
        return $this->helper->getSupportedRegions();
    }

    /**
     * Get country calling codes.
     *
     * @return array Array of country calling codes
     */
    public function getCountryCallingCodes(): array
    {
        return $this->helper->getSupportedCallingCodes();
    }

    /**
     * Normalize phone number.
     *
     * @param string $phone
     * @return string|null Normalized phone number or null if invalid
     */
    public function normalize(string $phone): ?string
    {
        // Remove all non-digit characters except +
        $normalized = preg_replace('/[^\d+]/', '', $phone);
        
        // Ensure it starts with + if it has country code
        if (strlen($normalized) > 10 && !str_starts_with($normalized, '+')) {
            $normalized = '+' . $normalized;
        }
        
        return $this->isValid($normalized) ? $normalized : null;
    }

    /**
     * Generate example phone numbers for testing.
     *
     * @param string $countryCode Country code (ISO 3166-1 alpha-2)
     * @param string $type Phone type (mobile, landline, toll_free)
     * @return array Array of example phone numbers
     */
    public function generateExamples(string $countryCode = 'US', string $type = 'mobile'): array
    {
        $examples = [
            'US' => [
                'mobile' => ['+14155552671', '+14155552672', '+14155552673'],
                'landline' => ['+14155552671', '+14155552672', '+14155552673'],
                'toll_free' => ['+18005552671', '+18005552672', '+18005552673'],
            ],
            'GB' => [
                'mobile' => ['+447700900000', '+447700900001', '+447700900002'],
                'landline' => ['+4420718340000', '+4420718340001', '+4420718340002'],
                'toll_free' => ['+448001234567', '+448001234568', '+448001234569'],
            ],
            'DE' => [
                'mobile' => ['+4915112345678', '+4915112345679', '+4915112345680'],
                'landline' => ['+493012345678', '+493012345679', '+493012345680'],
                'toll_free' => ['+498001234567', '+498001234568', '+498001234569'],
            ],
        ];
        
        return $examples[$countryCode][$type] ?? [];
    }
}
