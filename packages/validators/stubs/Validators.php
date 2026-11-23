<?php

// The validators of utopia-php/validators are registered by the utopia PHP extension
// (crates/extension in appwrite/appwrite). This file only declares them for static analysis and
// editors; it is never loaded.

namespace Utopia\Validator {
    abstract class Validator
    {
        public const TYPE_BOOLEAN = 'boolean';
        public const TYPE_INTEGER = 'integer';
        public const TYPE_FLOAT = 'double';
        /* gettype() returns 'double' for historical reasons */
        public const TYPE_STRING = 'string';
        public const TYPE_ARRAY = 'array';
        public const TYPE_OBJECT = 'object';
        public const TYPE_MIXED = 'mixed';
        /**
         * Get Description
         *
         * Returns validator description
         */
        abstract public function getDescription(): string;
        /**
         * Is array
         *
         * Returns true if an array or false if not.
         */
        abstract public function isArray(): bool;
        /**
         * Is valid
         *
         * Returns true if valid or false if not.
         *
         * @param  mixed  $value
         */
        abstract public function isValid($value): bool;
        /**
         * Get Type
         *
         * Returns validator type.
         */
        abstract public function getType(): string;
    }
    /**
     * Ensure all of the validators from a list passed the check
     *
     * @package Utopia\Validator
     */
    class AllOf extends Validator
    {
        protected ?Validator $failedRule = null;
        /**
         * @param array<Validator> $validators
         */
        public function __construct(protected array $validators, protected string $type = self::TYPE_MIXED)
        {
        }
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is valid
         *
         * Validation will pass when all rules are valid if only one of the rules is invalid validation will fail.
         */
        public function isValid(mixed $value): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
    }
    /**
     * Ensure at least one validator from a list passed the check
     *
     * @package Utopia\Validator
     */
    class AnyOf extends Validator
    {
        protected ?Validator $failedRule = null;
        /**
         * @param array<Validator> $validators
         */
        public function __construct(protected array $validators, protected string $type = self::TYPE_MIXED)
        {
        }
        /**
         * Get Validators
         *
         * Returns validators array
         *
         * @return array<Validator>
         */
        public function getValidators(): array
        {
        }
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is valid
         *
         * Validation will pass when all rules are valid if only one of the rules is invalid validation will fail.
         */
        public function isValid(mixed $value): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
    }
    /**
     * ArrayList
     *
     * Validate that an variable is a valid array value and each element passes given validation
     */
    class ArrayList extends Validator
    {
        /**
         * Array constructor.
         *
         * Pass a validator that must be applied to each element in this array
         */
        public function __construct(protected Validator $validator, protected int $length = 0)
        {
        }
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
        /**
         * Get Nested Validator
         */
        public function getValidator(): Validator
        {
        }
        /**
         * Is valid
         *
         * Validation will pass when $value is valid array and validator is valid.
         */
        public function isValid(mixed $value): bool
        {
        }
    }
    /**
     * ArrayList
     *
     * Validate that an variable is a valid array value and each element passes given validation
     */
    class Assoc extends Validator
    {
        /**
         * Pass integer length to allow larger json objects
         */
        public function __construct(protected int $length = 65535)
        {
        }
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
        /**
         * Is valid
         *
         * Validation will pass when $value is valid assoc array.
         *
         * @param  mixed  $value
         */
        public function isValid($value): bool
        {
        }
    }
    /**
     * Bool
     *
     * Validate that an variable is a boolean value
     */
    class Boolean extends Validator
    {
        /**
         * Pass true to accept true and false strings and integers 0 and 1 as valid boolean values
         * This option is good for validating query string params.
         */
        public function __construct(protected bool $loose = false)
        {
        }
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
        /**
         * Is valid
         *
         * Validation will pass when $value has a boolean value.
         *
         * @param  mixed  $value
         */
        public function isValid($value): bool
        {
        }
    }
    /**
     * Contains
     *
     * Validate that a string contains at least one of the predefined substrings.
     */
    class Contains extends Validator
    {
        protected array $patterns;
        /**
         * Constructor
         *
         * Sets a list of substrings to search for and strict mode.
         *
         * @param  bool  $strict enable case-sensitive matching
         */
        public function __construct(array $patterns, protected bool $strict = false)
        {
        }
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is valid
         *
         * Validation will pass when $value contains at least one of the patterns.
         *
         * @param  mixed  $value
         */
        public function isValid($value): bool
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
    }
    /**
     * Domain
     *
     * Validate that an variable is a valid domain address
     *
     * @package Utopia\Validator
     */
    class Domain extends Validator
    {
        /**
         * @param array<mixed> $restrictions Set of conditions that prevent validation from passing
         */
        public function __construct(protected array $restrictions = [], protected bool $hostnames = true, protected bool $allowEmpty = false)
        {
        }
        /**
         * Helper for creating domain restriction rule.
         * Such rules prevent validation from passing, so this behaves as deny-list.
         *
         * @param string $hostname A domain base, such as top-level domain or subdomain. Restriction is only applied if domain matches this hostname
         * @param int $levels Specify what level (top-level, subdomain, sub-subdomain, ..) domain must be. Example: "stage.appwrite.io" is level 3
         * @param array<string> $prefixDenyList Disallowed beginning of domain, useful for reserved behaviours, such as prefixing "branch-" for preview domains
         *
         */
        public static function createRestriction(string $hostname, ?int $levels = null, array $prefixDenyList = []): array
        {
        }
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is valid
         *
         * Validation will pass when $value is valid domain.
         *
         * Validates domain names against RFC 1034, RFC 1035, RFC 952, RFC 1123, RFC 2732, RFC 2181, and RFC 1123.
         *
         * @param  mixed $value
         */
        public function isValid($value): bool
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
    }
    /**
     * Float
     *
     * Validate that an variable is a float
     */
    class FloatValidator extends Validator
    {
        /**
         * Pass true to accept float strings as valid float values
         * This option is good for validating query string params.
         */
        public function __construct(protected bool $loose = false)
        {
        }
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
        /**
         * Is valid
         *
         * Validation will pass when $value is float.
         */
        public function isValid(mixed $value): bool
        {
        }
    }
    /**
     * Glob
     *
     * Validates a string against a list of gitignore-style glob patterns.
     * Supports * (single-segment wildcard), ** (multi-segment wildcard),
     * ? (single character that does not cross /), [abc] character classes,
     * \ escape sequences, and ! prefix for exclusions.
     *
     * Matching is case-sensitive. Inclusion patterns use OR semantics;
     * exclusion patterns use AND semantics. When both are present,
     * a specific inclusion overrides a wildcard exclusion.
     *
     * Pattern syntax follows the gitignore specification.
     * See https://git-scm.com/docs/gitignore for reference.
     */
    class Globstar extends Validator
    {
        public function __construct(private readonly array $patterns)
        {
        }
        /**
         * Get Description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is valid
         *
         * Returns true if $value matches the configured glob patterns.
         *
         * @param  mixed  $value
         */
        public function isValid($value): bool
        {
        }
        /**
         * Is array
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         */
        public function getType(): string
        {
        }
        /**
         * Match a subject against a single pattern.
         */
        private function match(string $subject, string $pattern): bool
        {
        }
        /**
         * Match using a regex built from a pattern that contains **.
         * Handles **, *, ?, [abc] character classes, and \ escape sequences.
         */
        private function matchGlobstar(string $subject, string $pattern): bool
        {
        }
    }
    class HexColor extends Validator
    {
        public function getDescription(): string
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
        public function isValid(mixed $value): bool
        {
        }
    }
    class Hostname extends Validator
    {
        /**
         * Constructor
         *
         * Sets allowed hostname patterns
         *
         * @param  string[]  $allowList
         */
        public function __construct(protected array $allowList = [])
        {
        }
        public function getDescription(): string
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
        public function isValid(mixed $value): bool
        {
        }
    }
    /**
     * Host
     *
     * Validate that a host is allowed from given whitelisted hosts list
     *
     * @package Utopia\Validator
     */
    class Host extends Validator
    {
        public function __construct(protected array $whitelist)
        {
        }
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is valid
         *
         * Validation will pass when $value starts with one of the given hosts
         *
         * @param  mixed $value
         */
        public function isValid($value): bool
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
    }
    /**
     * Identifier
     *
     * Validates that a value is a C-style identifier: letters, digits and
     * underscores only, not starting with a digit. This is the shape required of
     * environment variable names and shell identifiers, so a value that passes a
     * plain length check but contains a tab, a space or an accented letter is
     * rejected here.
     */
    class Identifier extends Text
    {
        public function __construct(int $length = 0)
        {
        }
        #[\Override]
        public function getDescription(): string
        {
        }
        #[\Override]
        public function isValid(mixed $value): bool
        {
        }
    }
    /**
     * Integer
     *
     * Validate that an variable is an integer
     */
    class Integer extends Validator
    {
        protected int $bits = 32;
        protected bool $unsigned = false;
        /**
         * Pass true to accept integer strings as valid integer values
         * This option is good for validating query string params.
         *
         * @param  int  $bits  Integer bit size (8, 16, 32, or 64)
         * @param  bool  $unsigned  Whether the integer is unsigned
         * @throws \InvalidArgumentException
         */
        public function __construct(protected bool $loose = false, int $bits = 32, bool $unsigned = false)
        {
        }
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
        /**
         * Get Bits
         *
         * Returns the bit size of the integer.
         */
        public function getBits(): int
        {
        }
        /**
         * Is Unsigned
         *
         * Returns whether the integer is unsigned.
         */
        public function isUnsigned(): bool
        {
        }
        /**
         * Get Format
         *
         * Returns the OpenAPI/JSON Schema format string for this integer configuration.
         */
        public function getFormat(): string
        {
        }
        private function getMin(): int
        {
        }
        private function getMax(): int
        {
        }
        /**
         * Is valid
         *
         * Validation will pass when $value is integer and within the specified bit range.
         */
        public function isValid(mixed $value): bool
        {
        }
    }
    /**
     * IP
     *
     * Validate that an variable is a valid IP address
     *
     * @package Utopia\Validator
     */
    class IP extends Validator
    {
        public const ALL = 'all';
        public const V4 = 'ipv4';
        public const V6 = 'ipv6';
        protected string $type;
        /**
         * Constructor
         *
         * Set a the type of IP check.
         */
        public function __construct(string $type = self::ALL)
        {
        }
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is valid
         *
         * Validation will pass when $value is valid IP address.
         *
         * @param  mixed $value
         */
        public function isValid($value): bool
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
    }
    class JSON extends Validator
    {
        public function getDescription(): string
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type
         */
        public function getType(): string
        {
        }
        public function isValid(mixed $value): bool
        {
        }
    }
    /**
     * Label
     *
     * Validates a single DNS label as RFC 1123 allows it in a hostname: 1 to 63
     * lowercase letters, digits and hyphens, not starting or ending with a hyphen.
     * Dots are rejected, so a value can be placed between fixed parts of a hostname
     * without adding or changing any other label.
     */
    class Label extends Validator
    {
        public const int MAX_LENGTH = 63;
        public function getDescription(): string
        {
        }
        public function isArray(): bool
        {
        }
        public function getType(): string
        {
        }
        public function isValid(mixed $value): bool
        {
        }
    }
    /**
     * Multiple
     *
     * Multiple validator is a container of multiple validations each acting as a rule.
     *
     * @package Utopia\Validator
     */
    class Multiple extends Validator
    {
        /**
         * @var Validator[]
         */
        protected $rules = [];
        /**
         * Constructor
         *
         * Multiple constructor can get any number of arguments containing Validator instances using PHP func_get_args function.
         *
         * Example:
         *
         * $multiple = new Multiple([$validator1, $validator2]);
         * $multiple = new Multiple([$validator1, $validator2, $validator3], self::TYPE_STRING);
         */
        public function __construct(array $rules, protected ?string $type = self::TYPE_MIXED)
        {
        }
        /**
         * Add rule
         *
         * Add a new rule to the end of the rules containing array
         */
        public function addRule(Validator $rule): static
        {
        }
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is valid
         *
         * Validation will pass when all rules are valid if only one of the rules is invalid validation will fail.
         */
        public function isValid(mixed $value): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
    }
    /**
     * Ensure no validators from a list passed the check
     *
     * @package Utopia\Validator
     */
    class NoneOf extends Validator
    {
        protected ?Validator $failedRule = null;
        /**
         * @param array<Validator> $validators
         */
        public function __construct(protected array $validators, protected string $type = self::TYPE_MIXED)
        {
        }
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is valid
         *
         * Validation will pass when all rules are valid if only one of the rules is invalid validation will fail.
         */
        public function isValid(mixed $value): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
    }
    class Nullable extends Validator
    {
        public function __construct(protected Validator $validator)
        {
        }
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
        public function getValidator(): Validator
        {
        }
        /**
         * Is valid
         *
         * Validation will pass when $value is text with valid length.
         */
        public function isValid(mixed $value): bool
        {
        }
    }
    /**
     * Numeric
     *
     * Validate that an variable is numeric
     */
    class Numeric extends Validator
    {
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
        /**
         * Is valid
         *
         * Validation will pass when $value is numeric.
         */
        public function isValid(mixed $value): bool
        {
        }
    }
    /**
     * Phone
     *
     * Validate that a variable is a valid E.164 phone number.
     */
    class Phone extends Validator
    {
        public function __construct(protected bool $allowEmpty = false, protected bool $normalize = false)
        {
        }
        /**
         * Recover E.164 phone numbers from URL/path transport damage.
         *
         * Path routers may leave a leading '+' percent-encoded as `%2B`, and some
         * HTTP stacks decode a bare '+' into a space. Some clients may also omit
         * the leading '+' when placing the number in a URL path.
         */
        public static function normalize(string $value): string
        {
        }
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
        /**
         * Is valid
         *
         * Validation will pass when $value is a valid E.164 phone number.
         */
        public function isValid(mixed $value): bool
        {
        }
    }
    /**
     * Port
     *
     * Validates a published port as Docker accepts it: a port (8080), or a port bound
     * to one host interface (127.0.0.1:8080, [::1]:8080).
     */
    class Port extends Validator
    {
        public function getDescription(): string
        {
        }
        public function isArray(): bool
        {
        }
        public function getType(): string
        {
        }
        public function isValid($value): bool
        {
        }
        private function isValidHost(string $host): bool
        {
        }
        private function isValidPort(string $port): bool
        {
        }
    }
    /**
     * Range
     *
     * Validates that an number is in range.
     */
    class Range extends Numeric
    {
        public function __construct(protected int|float $min, protected int|float $max, protected string $format = self::TYPE_INTEGER)
        {
        }
        /**
         * Get Range Minimum Value
         */
        public function getMin(): int|float
        {
        }
        /**
         * Get Range Maximum Value
         */
        public function getMax(): int|float
        {
        }
        /**
         * Get Range Format
         */
        public function getFormat(): string
        {
        }
        /**
         * Get Description
         *
         * Returns validator description
         */
        #[\Override]
        public function getDescription(): string
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        #[\Override]
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        #[\Override]
        public function getType(): string
        {
        }
        /**
         * Is valid
         *
         * Validation will pass when $value number is bigger or equal than $min number and lower or equal than $max.
         * Not strict, considers any valid integer to be a valid float
         * Considers infinity to be a valid integer
         */
        #[\Override]
        public function isValid(mixed $value): bool
        {
        }
    }
    /**
     * Text
     *
     * Validate that an variable is a valid text value
     */
    class Text extends Validator
    {
        public const NUMBERS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        public const ALPHABET_UPPER = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];
        public const ALPHABET_LOWER = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z'];
        /**
         * Text constructor.
         *
         * Validate text with maximum length $length. Use $length = 0 for unlimited length.
         * Optionally, provide allowList characters array $allowList to only allow specific character.
         * Set $requireNonBlank to reject values containing only Unicode separators, whitespace, or formatting characters.
         *
         * @param  string[]  $allowList
         */
        public function __construct(protected int $length, protected int $min = 1, protected array $allowList = [], protected bool $requireNonBlank = false)
        {
        }
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
        /**
         * Is valid
         *
         * Validation will pass when $value is text with valid length.
         */
        public function isValid(mixed $value): bool
        {
        }
    }
    /**
     * URL
     *
     * Validate that an variable is a valid URL
     *
     * @package Appwrite\Network\Validator
     */
    class URL extends Validator
    {
        public function __construct(protected array $allowedSchemes = [], protected bool $allowEmpty = false, protected bool $allowFragments = true, protected bool $allowPrivateUseSchemes = false, protected bool $httpsOrLoopback = false)
        {
        }
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is valid
         *
         * Validation will pass when $value is valid URL.
         *
         * @param  mixed $value
         */
        public function isValid($value): bool
        {
        }
        /**
         * Is private-use URI scheme
         * Returns true when $value is an authority-less private-use URI scheme
         * redirect URI as defined by RFC 8252 §7.1, e.g. "com.example.app:/oauth"
         * @param  mixed $value
         */
        private function isPrivateUseSchemeURI($value): bool
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
    }
    /**
     * WhiteList
     *
     * Checks if a variable is inside predefined white list.
     */
    class WhiteList extends Validator
    {
        /**
         * Constructor
         *
         * Sets a white list array and strict mode.
         *
         * @param  bool  $strict disable type check and be case insensetive
         * @param  string  $type of $list items
         */
        public function __construct(protected array $list, protected bool $strict = false, protected string $type = self::TYPE_STRING)
        {
        }
        /**
         * Get List of All Allowed Values
         */
        public function getList(): array
        {
        }
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
        /**
         * Is valid
         *
         * Validation will pass if $value is in the white list array.
         */
        public function isValid(mixed $value): bool
        {
        }
    }
    /**
     * Wildcard
     *
     * Does not perform any validation. Always returns valid
     */
    class Wildcard extends Validator
    {
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is valid
         *
         * Validation will always pass irrespective of input
         *
         * @param  mixed  $value
         */
        public function isValid($value): bool
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
    }
}

namespace Utopia\Validator\JSON {
    use Utopia\Validator\Validator;

    /**
     * ArrayValidator
     *
     * Validate that a variable is a JSON array, either already decoded or still encoded as a string.
     *
     * Named with a suffix because `Array` is a reserved class name in PHP.
     */
    class ArrayValidator extends Validator
    {
        /**
         * Pass an encoded length to cap the size of accepted arrays, 0 to allow any size
         */
        public function __construct(protected int $length = 0)
        {
        }
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
        /**
         * Is valid
         *
         * Validation will pass when $value is a JSON array, or a string encoding one.
         */
        public function isValid(mixed $value): bool
        {
        }
        private function hasValidLength(string|false $encoded): bool
        {
        }
    }
    /**
     * ObjectValidator
     *
     * Validate that a variable is a JSON object, either already decoded or still encoded as a string.
     *
     * Named with a suffix because `Object` is a reserved class name in PHP.
     */
    class ObjectValidator extends Validator
    {
        /**
         * Pass an encoded length to cap the size of accepted objects, 0 to allow any size
         */
        public function __construct(protected int $length = 0)
        {
        }
        /**
         * Get Description
         *
         * Returns validator description
         */
        public function getDescription(): string
        {
        }
        /**
         * Is array
         *
         * Function will return true if object is array.
         */
        public function isArray(): bool
        {
        }
        /**
         * Get Type
         *
         * Returns validator type.
         */
        public function getType(): string
        {
        }
        /**
         * Is valid
         *
         * Validation will pass when $value is a JSON object, or a string encoding one.
         */
        public function isValid(mixed $value): bool
        {
        }
        private function hasValidLength(string|false $encoded): bool
        {
        }
    }
    /**
     * Validate Google service account credentials used with the FCM HTTP v1 API.
     */
    class FCM extends Validator
    {
        /**
         * Fields required to authenticate and address an FCM HTTP v1 request.
         *
         * @var array<string, string>
         */
        private const array REQUIRED_FIELDS = ['type' => 'identifies the credentials as a Google service account', 'project_id' => 'identifies the Firebase project receiving messages', 'private_key' => 'signs the OAuth access-token request', 'client_email' => 'identifies the service account used for authentication', 'token_uri' => 'identifies the OAuth token endpoint'];
        /**
         * Standard service account fields that are validated when present.
         *
         * @var string[]
         */
        private const array OPTIONAL_FIELDS = ['private_key_id', 'client_id', 'auth_uri', 'auth_provider_x509_cert_url', 'client_x509_cert_url', 'universe_domain'];
        /**
         * @var string[]
         */
        private const array URL_FIELDS = ['auth_uri', 'token_uri', 'auth_provider_x509_cert_url', 'client_x509_cert_url'];
        private ?string $error = null;
        public function getDescription(): string
        {
        }
        public function isArray(): bool
        {
        }
        public function getType(): string
        {
        }
        public function isValid(mixed $value): bool
        {
        }
        private function isNonEmptyString(mixed $value): bool
        {
        }
        private function isHttpsUrl(string $value): bool
        {
        }
    }
}

namespace Utopia {
    /**
     * The base class's old name, which src/compat.php aliases to Validator\Validator until the
     * next major release.
     */
    abstract class Validator extends Validator\Validator
    {
    }
}
