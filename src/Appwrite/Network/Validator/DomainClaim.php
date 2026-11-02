<?php

namespace Appwrite\Network\Validator;

use Utopia\DNS\Message\Record;
use Utopia\Validator\Validator;

/**
 * A domain the given project may serve: either no project has claimed it, or this one has.
 *
 * DNS pointing at an installation's targets only proves the hostname reaches Appwrite, and
 * every project on the installation shares those targets. A TXT record on
 * `_appwrite.<domain>` holding a project ID names the project the hostname belongs to, so
 * once one is published it decides which project may serve the domain. The same record
 * proves ownership of a custom email domain.
 *
 * A domain with no such record stays valid for any project, keeping the DNS-target-only
 * onboarding flow working.
 */
class DomainClaim extends Validator
{
    public const string PREFIX = '_appwrite.';

    private string $reason = '';

    /**
     * @param string $projectId Project the claim must name, when the domain is claimed at all
     * @param array<string> $dnsServers DNS server IP(s) or domain(s) to use for validation
     * @param class-string<DNS> $dnsValidatorClass
     */
    public function __construct(
        private readonly string $projectId,
        private readonly array $dnsServers = [],
        private readonly string $dnsValidatorClass = DNS::class,
    ) {
    }

    public function getDescription(): string
    {
        return $this->reason !== ''
            ? $this->reason
            : 'Domain must be unclaimed or claimed by this project.';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }

    public function isValid(mixed $value): bool
    {
        $this->reason = '';

        if (!\is_string($value) || $value === '') {
            $this->reason = 'Domain is empty.';
            return false;
        }

        $name = self::PREFIX . $value;
        $validator = new $this->dnsValidatorClass($this->projectId, Record::TYPE_TXT, $this->dnsServers);

        if ($validator->isValid($name)) {
            return true;
        }

        $claims = \array_filter(\array_map('\trim', $validator->records), fn (string $claim): bool => $claim !== '');

        // No claim published, so the domain is free for the project that configured its DNS
        if ($claims === []) {
            return true;
        }

        if (\in_array($this->projectId, $claims, true)) {
            return true;
        }

        $this->reason = 'Domain ' . $value . ' is claimed by another project. '
            . 'Set the TXT record on ' . $name . ' to ' . $this->projectId . ' to use this domain in this project.';

        return false;
    }
}
