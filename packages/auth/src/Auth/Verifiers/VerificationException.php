<?php

declare(strict_types=1);

namespace Utopia\Auth\Verifiers;

/**
 * Thrown when a token fails verification — malformed input, an unexpected
 * algorithm, an invalid signature, or a claim that does not meet the
 * configured expectations.
 */
class VerificationException extends \Exception {}
