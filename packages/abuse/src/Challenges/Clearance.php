<?php

namespace Utopia\Abuse\Challenges;

/**
 * The credential a client gets after passing a challenge, so later requests can
 * skip it. It records the difficulty that was passed and when, and is bound to
 * whatever the caller chooses (client identity, resource). Verifying it is pure
 * crypto, so the hot path touches no store.
 *
 * The binding is subtractive: it can only make a copied clearance fail
 * elsewhere, never let through a client that holds none.
 */
final readonly class Clearance
{
    public const string DIFFICULTY_CLAIM = 'difficulty';

    public const string ISSUED_AT_CLAIM = 'issuedAt';

    public function __construct(private Token $token)
    {
    }

    /**
     * @param  array<string, string>  $binding  Verify must pass the same fields and values
     * @param  int  $difficulty  The difficulty of the challenge that was passed
     * @param  int  $ttl  Lifetime in seconds; 0 or less uses the token's default
     * @param  array<string, string|int>  $claims  Carried along, not checked
     *
     * @throws \InvalidArgumentException when a binding or claim uses a reserved name
     */
    public function issue(array $binding, int $difficulty = 0, int $ttl = 0, array $claims = []): string
    {
        foreach ([...\array_keys($binding), ...\array_keys($claims)] as $name) {
            if ($name === self::DIFFICULTY_CLAIM || $name === self::ISSUED_AT_CLAIM) {
                throw new \InvalidArgumentException("claim '{$name}' is reserved");
            }
        }

        return $this->token->issue([
            ...$claims,
            self::DIFFICULTY_CLAIM => $difficulty,
            // Not `iat`: the JWT library would expire the token at iat + its fixed max age
            self::ISSUED_AT_CLAIM => \time(),
        ], $ttl, $binding);
    }

    /**
     * True when the clearance is valid and unexpired, it is bound to exactly
     * this binding (every field, same values), it
     * was earned at no less than $difficulty, and it is younger than $maxAge
     * seconds (no age limit when $maxAge is 0 or less). A mismatch returns false
     * so the caller challenges again rather than blocking.
     *
     * @param  array<string, string>  $binding
     */
    public function verify(string $token, array $binding, int $difficulty = 0, int $maxAge = 0): bool
    {
        $claims = $this->token->verify($token);
        if ($claims === null) {
            return false;
        }

        if (!Token::isBoundTo($claims, $binding)) {
            return false;
        }

        $passed = $claims[self::DIFFICULTY_CLAIM] ?? null;
        $issuedAt = $claims[self::ISSUED_AT_CLAIM] ?? null;
        if (!\is_int($passed) || !\is_int($issuedAt)) {
            return false;
        }

        return $passed >= $difficulty
            && ($maxAge <= 0 || $issuedAt + $maxAge > \time());
    }
}
