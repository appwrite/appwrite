<?php

declare(strict_types=1);

namespace Utopia\DNS\Validator;

use Utopia\DNS\Message\Domain;
use Utopia\DNS\Message\Record;
use Utopia\Validator;

class Name extends Validator
{
    /**
     * Record types whose owner name must be a valid host name, where the
     * LDH rule applies (RFC 952, RFC 1123 section 2.1) and underscores are
     * forbidden. Owner names of all other record types follow the general
     * domain name rules (RFC 2181 section 11), where underscored labels
     * (RFC 8552) are legal - e.g. DKIM '_domainkey' CNAME/TXT records.
     */
    private const array RECORD_TYPES_WITH_HOSTNAME_OWNER = [Record::TYPE_A, Record::TYPE_AAAA];

    public const int LABEL_MAX_LENGTH = 63;

    public const string FAILURE_REASON_INVALID_LABEL_LENGTH = 'Label must be between 1 and 63 characters long';

    public const string FAILURE_REASON_INVALID_NAME_LENGTH = 'Name must be between 1 and 255 characters long';

    public const string FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE = 'Label must contain only alpha-numeric characters and hyphens, and cannot start or end with a hyphen';

    public const string FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE = 'Label must contain only alpha-numeric characters, hyphens and underscores, and cannot start or end with a hyphen';

    public const string FAILURE_REASON_INVALID_WILDCARD = 'Wildcard "*" must be the entire leftmost label';

    public const string FAILURE_REASON_GENERAL = 'Name must be between 1 and 255 characters long, and contain only alpha-numeric characters, hyphens and (for non-address record types) underscores, and cannot start or end with a hyphen';

    public string $reason = '';

    /**
     * @param int|null $recordType Record type code, or null to apply the general domain name rules.
     */
    public function __construct(private readonly ?int $recordType = null) {}

    /**
     * Check if the provided value matches the Name record format
     */
    public function isValid(mixed $name): bool
    {
        if (!\is_string($name)) {
            $this->reason = self::FAILURE_REASON_GENERAL;
            return false;
        }

        // DNS names are made up of labels separated by dots.
        // Each label: 1-63 chars, letters, digits, hyphens, can't start/end w/ hyphen.
        // Full name: <=255 chars, labels separated by single dots, no empty labels unless root.

        if (\strlen($name) < 1 || \strlen($name) > Domain::MAX_DOMAIN_NAME_LEN) {
            $this->reason = self::FAILURE_REASON_INVALID_NAME_LENGTH;
            return false;
        }

        // Special case for referencing the zone origin
        if ($name === '@') {
            return true;
        }

        // If the name ends with '.', strip it (absolute FQDN); allow trailing '.'.
        $trimmed = (str_ends_with($name, '.')) ? substr($name, 0, -1) : $name;

        // RFC 4592: a wildcard is a single '*' as the entire leftmost label.
        if ($trimmed === '*') {
            return true;
        }
        if (str_starts_with($trimmed, '*.')) {
            $trimmed = substr($trimmed, 2);
        }
        if (str_contains($trimmed, '*')) {
            $this->reason = self::FAILURE_REASON_INVALID_WILDCARD;
            return false;
        }

        $labels = explode('.', $trimmed);

        $isUnderscoreAllowed = !\in_array($this->recordType, self::RECORD_TYPES_WITH_HOSTNAME_OWNER, true);

        foreach ($labels as $label) {
            if ($label === '') {
                $this->reason = $isUnderscoreAllowed ? self::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE : self::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE;
                return false;
            }

            if (\strlen($label) > self::LABEL_MAX_LENGTH) {
                $this->reason = self::FAILURE_REASON_INVALID_LABEL_LENGTH;
                return false;
            }

            $len = \strlen($label);
            for ($i = 0; $i < $len; ++$i) {
                if (!$this->isValidCharacter($label[$i], $i === 0 || $i === $len - 1, $isUnderscoreAllowed)) {
                    $this->reason = $isUnderscoreAllowed ? self::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE : self::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE;
                    return false;
                }
            }
        }

        return true;
    }

    private function isValidCharacter(string $char, bool $isFirstOrLast, bool $isUnderscoreAllowed): bool
    {
        if ($isFirstOrLast) {
            return ctype_alnum($char) || ($isUnderscoreAllowed && $char === '_');
        }
        return ctype_alnum($char) || $char === '-' || ($isUnderscoreAllowed && $char === '_');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        if ($this->reason !== '' && $this->reason !== '0') {
            return $this->reason;
        }

        return self::FAILURE_REASON_GENERAL;
    }

    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return self::TYPE_STRING;
    }

    /**
     * @inheritDoc
     */
    public function isArray(): bool
    {
        return false;
    }
}
