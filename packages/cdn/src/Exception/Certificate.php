<?php

declare(strict_types=1);

namespace Utopia\Cdn\Exception;

use Utopia\Cdn\Certificates\Challenge;
use Utopia\Cdn\Certificates\Status;

/**
 * The provider cannot finish issuing a certificate without the domain owner.
 *
 * Thrown by `Provider::getCertificateStatus()` when the certificate authority
 * is blocked on domain validation, or has stopped trying. The challenges are
 * the DNS records that unblock it; the warnings are the provider's own
 * instructions, for example about records that conflict with a challenge.
 */
class Certificate extends \RuntimeException
{
    /**
     * @param string $status `Status::BLOCKED` while the provider waits on the domain owner, `Status::FAILED` once it has stopped trying.
     * @param list<Challenge> $challenges
     * @param list<string> $warnings
     */
    public function __construct(
        string $message,
        private readonly string $status,
        private readonly array $challenges = [],
        private readonly array $warnings = [],
    ) {
        parent::__construct($message);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /** @return list<Challenge> */
    public function getChallenges(): array
    {
        return $this->challenges;
    }

    /** @return list<string> */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function isBlocked(): bool
    {
        return $this->status === Status::BLOCKED;
    }
}
