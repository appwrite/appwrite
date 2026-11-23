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
    /** Lifetime in seconds (30 minutes). Short on purpose: it tolerates IP drift. */
    public const int DEFAULT_TTL = 1800;

    public const string DIFFICULTY_CLAIM = 'difficulty';

    public const string ISSUED_AT_CLAIM = 'issuedAt';

    private Token $token;

    /** @var array<string, string> */
    private array $binding;

    /**
     * @param  string  $secret  Signing secret
     * @param  int  $ttl  Default lifetime in seconds
     * @param  string  $label  Derives the signing key from the secret; keep it stable or issued clearances stop verifying
     * @param  string  $purpose  Stored in each clearance so no other kind of token passes as one
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $secret,
        int $ttl = self::DEFAULT_TTL,
        string $label = 'clearance',
        string $purpose = 'clearance',
    ) {
        $this->token = new Token($secret, $label, $purpose, $ttl);
        $this->binding = [];
    }

    /**
     * A copy bound to these fields, e.g. the client's IP prefix, User-Agent
     * hash or the resource it was earned on. Clearances it issues verify only
     * with the same binding.
     *
     * @param  array<string, string>  $binding
     */
    public function withBinding(array $binding): static
    {
        return clone($this, ['binding' => [...$this->binding, ...$binding]]);
    }

    /**
     * @param  int  $difficulty  The difficulty of the challenge that was passed
     * @param  int  $ttl  Lifetime in seconds; 0 or less uses the default
     * @param  array<string, string|int>  $claims  Carried along, not checked
     *
     * @throws \InvalidArgumentException when a binding field or claim uses a reserved name
     */
    public function issue(int $difficulty = 0, int $ttl = 0, array $claims = []): string
    {
        foreach ([...\array_keys($this->binding), ...\array_keys($claims)] as $name) {
            if ($name === self::DIFFICULTY_CLAIM || $name === self::ISSUED_AT_CLAIM) {
                throw new \InvalidArgumentException("claim '{$name}' is reserved");
            }
        }

        return $this->token->issue([
            ...$claims,
            self::DIFFICULTY_CLAIM => $difficulty,
            // Not `iat`: the JWT library would expire the token at iat + its fixed max age
            self::ISSUED_AT_CLAIM => \time(),
        ], $ttl, $this->binding);
    }

    /**
     * True when the clearance is valid and unexpired, bound to exactly this
     * binding, earned at no less than $difficulty, and younger than $maxAge
     * seconds (no age limit when $maxAge is 0 or less). A mismatch returns false
     * so the caller challenges again rather than blocking.
     */
    public function verify(string $token, int $difficulty = 0, int $maxAge = 0): bool
    {
        $claims = $this->token->verify($token);
        if ($claims === null || !Token::isBoundTo($claims, $this->binding)) {
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
