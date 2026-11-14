<?php

namespace Appwrite\Alert\Probe;

/**
 * The outcome of one check: its result, how long it took in milliseconds, the
 * last status or reply code the target sent, and when its TLS certificate
 * expires where the check used TLS.
 */
readonly class Check
{
    public function __construct(
        public Result $result,
        public int $latency,
        public ?int $code = null,
        public ?\DateTimeImmutable $certificateExpiresAt = null,
    ) {
    }
}
