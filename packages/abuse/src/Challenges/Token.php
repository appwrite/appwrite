<?php

namespace Utopia\Abuse\Challenges;

use Ahc\Jwt\JWT;
use Throwable;

/**
 * A signed, expiring token issued for one purpose. The signing key is derived
 * from the secret and a label, and the purpose is stored in the token, so a
 * token issued for one purpose never verifies as another even when both share a
 * secret.
 */
final readonly class Token
{
    public const string PURPOSE_CLAIM = 'purpose';

    public const string EXPIRY_CLAIM = 'exp';

    private JWT $jwt;

    /**
     * @param  string  $secret  Signing secret; never used as the key directly
     * @param  string  $label  Derives this token's signing key from the secret
     * @param  string  $purpose  Stored in every token and required on verify
     * @param  int  $ttl  Default lifetime in seconds
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $secret,
        string $label,
        public string $purpose,
        public int $ttl,
    ) {
        if ($secret === '') {
            throw new \InvalidArgumentException('secret must not be empty');
        }
        if ($label === '' || $purpose === '') {
            throw new \InvalidArgumentException('label and purpose must not be empty');
        }
        if ($ttl <= 0) {
            throw new \InvalidArgumentException('ttl must be greater than 0');
        }

        $this->jwt = new JWT(\hash_hmac('sha256', $label, $secret), 'HS256', $ttl, 0);
    }

    /**
     * Sign the claims with this token's purpose and an expiry.
     *
     * @param  array<string, string|int>  $claims
     * @param  int  $ttl  Lifetime in seconds; 0 or less uses the default
     *
     * @throws \InvalidArgumentException when a claim uses a reserved name
     */
    public function issue(array $claims, int $ttl = 0): string
    {
        foreach ([self::PURPOSE_CLAIM, self::EXPIRY_CLAIM, 'iat'] as $reserved) {
            if (\array_key_exists($reserved, $claims)) {
                throw new \InvalidArgumentException("claim '{$reserved}' is reserved");
            }
        }

        return $this->jwt->encode([
            self::PURPOSE_CLAIM => $this->purpose,
            ...$claims,
            self::EXPIRY_CLAIM => \time() + ($ttl > 0 ? $ttl : $this->ttl),
        ]);
    }

    /**
     * The claims of a token with a valid signature, unexpired, issued for this
     * purpose; null otherwise.
     *
     * @return array<mixed>|null
     */
    public function verify(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        try {
            $claims = $this->jwt->decode($token);
        } catch (Throwable) {
            return null;
        }

        if (($claims[self::PURPOSE_CLAIM] ?? null) !== $this->purpose) {
            return null;
        }

        return $claims;
    }
}
