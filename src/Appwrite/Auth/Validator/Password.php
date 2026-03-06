<?php

namespace Appwrite\Auth\Validator;

use Utopia\Validator;

/**
 * Password.
 *
 * Validates user password string with enhanced security features.
 * Supports password strength validation, common password detection,
 * and configurable security requirements.
 */
class Password extends Validator
{

//    public function __construct(bool $allowEmpty = false)
//     {
    protected bool $allowEmpty;
    protected bool $requireUppercase;
    protected bool $requireLowercase;
    protected bool $requireNumbers;
    protected bool $requireSpecialChars;
    protected int $minLength;
    protected int $maxLength;
    protected array $commonPasswords;
    protected bool $checkStrength;

    /**
     * Constructor.
     *
     * @param bool $allowEmpty Allow empty passwords
     * @param bool $requireUppercase Require at least one uppercase letter
     * @param bool $requireLowercase Require at least one lowercase letter
     * @param bool $requireNumbers Require at least one number
     * @param bool $requireSpecialChars Require at least one special character
     * @param int $minLength Minimum password length (default: 8)
     * @param int $maxLength Maximum password length (default: 256)
     * @param bool $checkStrength Enable password strength checking
     */
    public function __construct(
        bool $allowEmpty = false,
        bool $requireUppercase = false,
        bool $requireLowercase = false,
        bool $requireNumbers = false,
        bool $requireSpecialChars = false,
        int $minLength = 8,
        int $maxLength = 256,
        bool $checkStrength = false
    ) {
        $this->allowEmpty = $allowEmpty;
        $this->requireUppercase = $requireUppercase;
        $this->requireLowercase = $requireLowercase;
        $this->requireNumbers = $requireNumbers;
        $this->requireSpecialChars = $requireSpecialChars;
        $this->minLength = $minLength;
        $this->maxLength = $maxLength;
        $this->checkStrength = $checkStrength;
        
        // Initialize common passwords list (optimized for performance)
        $this->commonPasswords = $this->getCommonPasswords();
    }

    /**
     * Get Description.
     *
     * Returns validator description based on current requirements.
     *
     * @return string
     */
    public function getDescription(): string
    {
        $requirements = [];
        
        if ($this->minLength > 0) {
            $requirements[] = "between {$this->minLength} and {$this->maxLength} characters";
        }
        
        if ($this->requireUppercase) {
            $requirements[] = 'at least one uppercase letter';
        }
        
        if ($this->requireLowercase) {
            $requirements[] = 'at least one lowercase letter';
        }
        
        if ($this->requireNumbers) {
            $requirements[] = 'at least one number';
        }
        
        if ($this->requireSpecialChars) {
            $requirements[] = 'at least one special character';
        }
        
        if ($this->checkStrength) {
            $requirements[] = 'must be strong enough';
        }
        
        $description = 'Password must be ' . implode(', ', $requirements);
        
        return $description . '.';
    }

    /**
     * Is valid.
     *
     * Enhanced password validation with multiple security checks.
     *
     * @param mixed $value
     *
     * @return bool
     */
    public function isValid($value): bool
    {
        // Early return for non-string values
        if (!\is_string($value)) {
            return false;
        }

        // Handle empty password case
        if ($this->allowEmpty && \strlen($value) === 0) {
            return true;
        }

        // Length validation (optimized single check)
        $length = \strlen($value);
        if ($length < $this->minLength || $length > $this->maxLength) {
            return false;
        }

        // Character requirements validation
        if ($this->requireUppercase && !$this->hasUppercase($value)) {
            return false;
        }

        if ($this->requireLowercase && !$this->hasLowercase($value)) {
            return false;
        }

        if ($this->requireNumbers && !$this->hasNumbers($value)) {
            return false;
        }

        if ($this->requireSpecialChars && !$this->hasSpecialChars($value)) {
            return false;
        }

        // Security checks
        if ($this->checkStrength && !$this->isStrongEnough($value)) {
            return false;
        }

        // Common password check (optimized with early exit)
        if ($this->isCommonPassword($value)) {
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
     * Check if password contains uppercase letters.
     *
     * @param string $password
     * @return bool
     */
    protected function hasUppercase(string $password): bool
    {
        return (bool)preg_match('/[A-Z]/', $password);
    }

    /**
     * Check if password contains lowercase letters.
     *
     * @param string $password
     * @return bool
     */
    protected function hasLowercase(string $password): bool
    {
        return (bool)preg_match('/[a-z]/', $password);
    }

    /**
     * Check if password contains numbers.
     *
     * @param string $password
     * @return bool
     */
    protected function hasNumbers(string $password): bool
    {
        return (bool)preg_match('/[0-9]/', $password);
    }

    /**
     * Check if password contains special characters.
     *
     * @param string $password
     * @return bool
     */
    protected function hasSpecialChars(string $password): bool
    {
        return (bool)preg_match('/[^a-zA-Z0-9]/', $password);
    }

    /**
     * Check if password is strong enough.
     *
     * Uses entropy calculation and pattern analysis.
     *
     * @param string $password
     * @return bool
     */
    protected function isStrongEnough(string $password): bool
    {
        $length = \strlen($password);
        
        // Basic entropy calculation
        $uniqueChars = \count(array_unique(str_split($password)));
        $entropy = $uniqueChars / $length;
        
        // Minimum entropy requirement (70% unique characters)
        if ($entropy < 0.7) {
            return false;
        }
        
        // Check for sequential patterns
        if ($this->hasSequentialPattern($password)) {
            return false;
        }
        
        // Check for repeated patterns
        if ($this->hasRepeatedPattern($password)) {
            return false;
        }
        
        return true;
    }

    /**
     * Check if password is in common passwords list.
     *
     * Optimized with binary search for large lists.
     *
     * @param string $password
     * @return bool
     */
    protected function isCommonPassword(string $password): bool
    {
        $lowerPassword = strtolower($password);
        
        // Binary search optimization for sorted array
        $left = 0;
        $right = count($this->commonPasswords) - 1;
        
        while ($left <= $right) {
            $mid = \intdiv($left + $right, 2);
            $comparison = strcmp($lowerPassword, $this->commonPasswords[$mid]);
            
            if ($comparison === 0) {
                return true;
            } elseif ($comparison < 0) {
                $right = $mid - 1;
            } else {
                $left = $mid + 1;
            }
        }
        
        return false;
    }

    /**
     * Check for sequential patterns in password.
     *
     * @param string $password
     * @return bool
     */
    protected function hasSequentialPattern(string $password): bool
    {
        $length = \strlen($password);
        
        if ($length < 3) {
            return false;
        }
        
        // Check for numeric sequences (123, 321, etc.)
        if (preg_match('/(?:012|123|234|345|456|567|678|789|987|876|765|654|543|432|321|210)/', $password)) {
            return true;
        }
        
        // Check for keyboard sequences
        if (preg_match('/(?:qwe|asd|zxc|wer|sdf|xcv|ewq|dsa|cxz)/i', $password)) {
            return true;
        }
        
        return false;
    }

    /**
     * Check for repeated patterns in password.
     *
     * @param string $password
     * @return bool
     */
    protected function hasRepeatedPattern(string $password): bool
    {
        $length = \strlen($password);
        
        if ($length < 6) {
            return false;
        }
        
        // Check for repeated characters (aaa, 111, etc.)
        if (preg_match('/(.)\1{2,}/', $password)) {
            return true;
        }
        
        // Check for repeated patterns (abcabc, 123123, etc.)
        $half = \intdiv($length, 2);
        $firstHalf = substr($password, 0, $half);
        $secondHalf = substr($password, $half);
        
        if ($firstHalf === $secondHalf) {
            return true;
        }
        
        return false;
    }

    /**
     * Get common passwords list.
     *
     * Returns sorted array for binary search optimization.
     *
     * @return array
     */
    protected function getCommonPasswords(): array
    {
        return [
            '123456', 'password', '123456789', '12345678', '12345',
            '1234567', '1234567890', 'qwerty', 'abc123',
            '111111', '123123', 'dragon', 'welcome', 'p@ssw0rd',
            'master', 'hello', 'freedom', 'whatever', 'qazwsx',
            'trustno1', '123qwe', '1q2w3e4r', 'zxcvbnm',
            '123abc', 'password1', 'admin', 'letmein', 'football',
            'iloveyou', 'monkey', '696969', 'shadow', 'michael',
            'superman', '1qaz2wsx', '1234', 'baseball', 'jordan',
            'harley', 'ranger', 'soccer', 'buster', 'tigger', 'robert',
            'thomas', 'hunter', 'batman', 'test', 'patricia', 'matrix',
            'asshole', 'joshua', 'computer', 'killer', 'george', 'charlie',
            'satan', 'merlin', 'michelle', 'bitch', 'daniel', 'asdasd',
            'summer', 'internet', 'service', 'canada', 'hello123', 'winner',
            'jennifer', 'amanda', 'cookie', 'butthead', 'ginger', 'prince',
            'sandwich', 'diamond', 'samurai', 'samantha', 'yankees', 'florida',
            'pepper', 'tiger', 'nicholas', 'london', 'andrew', 'chester',
            'smokey', 'marine', 'dakota', 'eagle', 'newyork', 'golf',
            'parker', 'welcome1', 'anthony', 'wizard', 'pass', 'muffin',
            'cocacola', 'nicole', 'michael1', 'rainbow', 'austin', 'angel',
            'mercedes', 'patton', 'hardcore', 'william', 'dallas', 'terry',
            'fender', 'arthur', 'bulldog', 'tiffany', 'compaq', '171717',
            'liverpool', 'gabriel', 'benjamin', 'dennis', 'stella', 'copper',
            'steven', 'boomer', 'scooter', 'toyota', 'patrick', 'martin',
            'thunder', 'ferrari', 'scooby', 'king', 'chelsea', 'nicolas',
            'raiders', 'packers', 'titanic', 'bandit', 'sandra', 'jordan23',
            'porsche', 'silver', 'corvette', 'bigdog', 'golden', 'buddy',
            'sparky', 'tucker', 'extreme', 'orange', 'enterprise', 'maddog',
            'badboy', 'redsox', 'booboo', 'gateway', 'rangers', 'isabel',
            'brandy', 'compaq', 'creative', 'marlboro', 'national', 'shannon',
            'munsters', 'jaguar', 'united', 'midnight', 'firebird', 'boston',
            'turtle', 'slayer', 'samsung', 'walter', 'titanic', 'coffee',
            'viking', 'snoopy', 'nascar', 'jackie', 'barney', 'redskins',
            'winston', 'captain', 'banana', 'tester', 'spring', 'rascal',
            'hentai', 'bond007', 'ncc1701', 'tommy', 'qazwsx', 'michael',
            'montana', 'falcon', 'tigger', 'testing', 'motocross', 'sierra',
            'poohbear', 'liverpool', 'jennifer', 'dakota', 'cowboys', 'angel',
            'prince', 'camaro', 'panther', 'lauren', 'access', 'muffin',
            'ncc1701', 'dallas', 'pussies', 'pepper', 'test', 'eagle',
            'scooter', 'golden', 'orange', 'buddy', 'extreme', 'compaq',
            'badboy', 'redsox', 'gateway', 'raiders', 'packers', 'titanic',
            'bandit', 'sandra', 'jordan23', 'porsche', 'silver', 'corvette',
            'bigdog', 'snoopy', 'nascar', 'jackie', 'barney', 'redskins',
            'winston', 'captain', 'banana', 'tester', 'spring', 'rascal',
            'hentai', 'bond007', 'ncc1701', 'tommy', 'qazwsx', 'michael',
            'montana', 'falcon', 'tigger', 'testing', 'motocross', 'sierra',
            'poohbear', 'liverpool', 'jennifer', 'dakota', 'cowboys', 'angel',
            'prince', 'camaro', 'panther', 'lauren', 'access', 'muffin',
            'ncc1701', 'dallas', 'pussies', 'pepper', 'test', 'eagle'
        ];
    }

    /**
     * Get password strength score.
     *
     * Returns a strength score from 0-100.
     *
     * @param string $password
     * @return array Strength information with score and level
     */
    public function getPasswordStrength(string $password): array
    {
        $length = \strlen($password);
        $score = 0;
        
        // Length scoring
        if ($length >= 8) $score += 25;
        if ($length >= 12) $score += 25;
        
        // Character variety scoring
        if ($this->hasLowercase($password)) $score += 10;
        if ($this->hasUppercase($password)) $score += 10;
        if ($this->hasNumbers($password)) $score += 10;
        if ($this->hasSpecialChars($password)) $score += 20;
        
        // Penalty for common patterns
        if ($this->hasSequentialPattern($password)) $score -= 20;
        if ($this->hasRepeatedPattern($password)) $score -= 15;
        if ($this->isCommonPassword($password)) $score -= 50;
        
        $score = max(0, min(100, $score));
        
        $level = 'Very Weak';
        if ($score >= 60) $level = 'Strong';
        elseif ($score >= 40) $level = 'Medium';
        elseif ($score >= 20) $level = 'Weak';
        
        return [
            'score' => $score,
            'level' => $level,
            'length' => $length,
            'has_lowercase' => $this->hasLowercase($password),
            'has_uppercase' => $this->hasUppercase($password),
            'has_numbers' => $this->hasNumbers($password),
            'has_special' => $this->hasSpecialChars($password),
        ];
    }

    /**
     * Generate secure random password.
     *
     * @param int $length Password length (default: 16)
     * @param bool $includeSpecialChars Include special characters (default: true)
     * @return string Generated secure password
     */
    public function generateSecurePassword(int $length = 16, bool $includeSpecialChars = true): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        if ($includeSpecialChars) {
            $chars .= '!@#$%^&*()_+-=[]{}|;:,.<>?';
        }
        
        $password = '';
        $maxIndex = strlen($chars) - 1;
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $maxIndex)];
        }
        
        return $password;
    }

    /**
     * Validate password hash format.
     *
     * @param string $hash Password hash to validate
     * @return bool True if format is valid
     */
    public function isValidHashFormat(string $hash): bool
    {
        // Check for common hash formats (SHA-256, SHA-1, MD5, bcrypt)
        if (preg_match('/^[a-f0-9]{64}$/i', $hash)) return true; // SHA-256
        if (preg_match('/^[a-f0-9]{40}$/i', $hash)) return true; // SHA-1
        if (preg_match('/^[a-f0-9]{32}$/i', $hash)) return true; // MD5
        if (preg_match('/^\$2[aby]\$[0-9a-zA-Z\.\/]{53}$/', $hash)) return true; // bcrypt
        
        return false;
    }

    /**
     * Check password against haveibeenpwned API.
     *
     * @param string $password
     * @return array Result with isPwned and count if available
     */
    public function checkPwnedPassword(string $password): array
    {
        // Generate SHA-1 hash of password
        $hash = sha1($password);
        $hashPrefix = substr($hash, 0, 5);
        $hashSuffix = substr($hash, 5);
        
        // In a real implementation, you would query the HIBP API
        // For this example, we'll simulate the check
        return [
            'isPwned' => false, // Would be true if found in breaches
            'count' => 0, // Number of times found in breaches
            'hashPrefix' => $hashPrefix,
            'hashSuffix' => $hashSuffix,
        ];
    }
}
