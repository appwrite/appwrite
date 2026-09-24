<?php

declare(strict_types=1);

namespace Utopia\Auth\Verifiers;

use Utopia\Auth\Verifier;

/**
 * Verifies tokens signed symmetrically with HS256.
 *
 * Holds the same shared secret used to sign the token, so it can validate
 * tokens minted by {@see \Utopia\Auth\Issuers\Symmetric} (e.g. a refresh
 * token). The secret never leaves the issuing party, so these tokens cannot
 * be verified by third parties.
 */
class Symmetric extends Verifier
{
    /**
     * @param  string  $secret  The shared signing secret used to verify the signature.
     * @param  string|null  $issuer  Required "iss" claim; not checked when null.
     * @param  string|array<int, string>|null  $audience  Acceptable "aud" value(s); not checked when null.
     * @param  string|null  $type  Required "typ" header (e.g. "JWT"); not checked when null.
     * @param  bool  $allowExpired  Skip the "exp" check when true; "nbf"/"iat" stay enforced.
     * @param  int  $leeway  Clock-skew tolerance in seconds.
     *
     * @throws \Exception When the secret is missing.
     */
    public function __construct(
        protected readonly string $secret,
        ?string $issuer = null,
        string|array|null $audience = null,
        ?string $type = null,
        bool $allowExpired = false,
        int $leeway = 0,
    ) {
        if ($secret === '' || $secret === '0') {
            throw new \Exception('A signing secret is required');
        }

        parent::__construct($issuer, $audience, $type, $allowExpired, $leeway);
    }

    protected function getAlgorithm(): string
    {
        return 'HS256';
    }

    protected function verifySignature(string $signingInput, string $signature): bool
    {
        $expected = hash_hmac('sha256', $signingInput, $this->secret, true);

        return hash_equals($expected, $signature);
    }
}
