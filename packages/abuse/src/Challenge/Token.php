<?php

namespace Utopia\Abuse\Challenge;

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

    /** Names of the claims that form the binding, so verify can require all of them. */
    public const string BINDING_CLAIM = 'binding';

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
     * Sign the claims with this token's purpose and an expiry, bound to the
     * given fields. The binding's values are stored as claims and its names
     * recorded, so {@see isBoundTo()} can require the whole binding.
     *
     * @param  array<string, string|int>  $claims
     * @param  int  $ttl  Lifetime in seconds; 0 or less uses the default
     * @param  array<string, string>  $binding
     *
     * @throws \InvalidArgumentException when a name is reserved or used by both claims and binding
     */
    public function issue(array $claims, int $ttl = 0, array $binding = []): string
    {
        foreach ([...\array_keys($claims), ...\array_keys($binding)] as $name) {
            if (\in_array($name, [self::PURPOSE_CLAIM, self::EXPIRY_CLAIM, self::BINDING_CLAIM, 'iat'], true)) {
                throw new \InvalidArgumentException("claim '{$name}' is reserved");
            }
        }
        if (\array_intersect_key($binding, $claims) !== []) {
            throw new \InvalidArgumentException('binding and claims must not share names');
        }

        $names = \array_keys($binding);
        \sort($names);

        return $this->jwt->encode([
            self::PURPOSE_CLAIM => $this->purpose,
            ...$claims,
            ...$binding,
            self::BINDING_CLAIM => $names,
            self::EXPIRY_CLAIM => \time() + ($ttl > 0 ? $ttl : $this->ttl),
        ]);
    }

    /**
     * The claims of a token with a valid signature and an expiry not yet
     * passed, issued for this purpose; null otherwise.
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

        // The library checks `exp` only when present; a token without one would never expire.
        if (($claims[self::PURPOSE_CLAIM] ?? null) !== $this->purpose || !\is_int($claims[self::EXPIRY_CLAIM] ?? null)) {
            return null;
        }

        return $claims;
    }

    /**
     * True when the verified claims are bound to exactly this binding: the same
     * field names, each with the same value. Leaving a field out fails, so a
     * caller cannot drop part of the binding by accident.
     *
     * @param  array<mixed>  $claims  From {@see verify()}
     * @param  array<string, string>  $binding
     */
    public static function isBoundTo(array $claims, array $binding): bool
    {
        $names = $claims[self::BINDING_CLAIM] ?? null;
        if (!\is_array($names)) {
            return false;
        }

        $expected = \array_keys($binding);
        \sort($expected);
        if ($names !== $expected) {
            return false;
        }

        return \array_all($binding, fn (string $value, string $name): bool => ($claims[$name] ?? null) === $value);
    }
}
