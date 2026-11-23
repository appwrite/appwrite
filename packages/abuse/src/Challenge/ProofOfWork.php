<?php

namespace Utopia\Abuse\Challenge;

use Throwable;
use Utopia\Abuse\Challenge;
use Utopia\Abuse\Challenge\ProofOfWork\Puzzle;

/**
 * Leading-zero-bits proof-of-work. A client must find a solution whose
 * SHA-256(nonce ":" solution) starts with at least `difficulty` zero bits.
 *
 * Issue signs the nonce and difficulty into a token, with no server-side write.
 * Verify checks the token and params, uses the nonce once before checking the
 * solution (so one puzzle buys one attempt), then checks the solution.
 */
final readonly class ProofOfWork extends Challenge
{
    /** SHA-256 digest width: the most leading zero bits a solution can have. */
    public const int MAX_DIFFICULTY = 256;

    /** Puzzle lifetime in seconds; also how long a used nonce is remembered. */
    public const int DEFAULT_TTL = 120;

    /**
     * @param  string  $secret  Signing secret
     * @param  Nonce  $nonces  Remembers used nonces; key placeholders such as "{project}" are filled from the puzzle's claims
     * @param  int  $difficulty  Leading zero bits a solution needs, 0–256; issue() can override it per puzzle
     * @param  int  $ttl  Puzzle lifetime in seconds
     * @param  bool  $acceptWhenNoncesUnavailable  When the nonce store fails, check the solution anyway instead of rejecting
     * @param  string  $label  Derives the signing key from the secret; keep it stable or issued puzzles stop verifying
     * @param  string  $purpose  Stored in each puzzle so no other kind of token passes as one
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $secret,
        private Nonce $nonces,
        private int $difficulty,
        int $ttl = self::DEFAULT_TTL,
        private bool $acceptWhenNoncesUnavailable = false,
        string $label = 'challenge',
        string $purpose = 'challenge',
    ) {
        self::assertDifficulty($difficulty);
        parent::__construct($secret, $label, $purpose, $ttl);
    }

    /**
     * @param  array<string, string|int>  $claims
     * @param  int|null  $difficulty  Overrides the constructor's difficulty for this puzzle
     *
     * @throws \InvalidArgumentException
     */
    #[\Override]
    public function issue(array $claims = [], ?int $difficulty = null): Puzzle
    {
        $difficulty ??= $this->difficulty;
        self::assertDifficulty($difficulty);

        $nonce = \bin2hex(\random_bytes(16));
        $token = $this->sign([
            'nonce' => $nonce,
            'difficulty' => $difficulty,
            'iatMs' => (int) (\microtime(true) * 1000),
        ], $claims);

        return new Puzzle($token, $nonce, $difficulty);
    }

    /**
     * Verify a solution. Params are checked before the nonce is used, so a
     * puzzle presented by another client never spends the owner's attempt.
     */
    #[\Override]
    public function verify(string $token, string $answer): Result
    {
        $claims = $this->open($token);
        if ($claims instanceof Result) {
            return $claims;
        }

        $nonce = $claims['nonce'] ?? null;
        $difficulty = $claims['difficulty'] ?? null;
        if (!\is_string($nonce) || $nonce === '' || !\is_int($difficulty) || $difficulty < 0 || $difficulty > self::MAX_DIFFICULTY) {
            return new Result(Status::InvalidToken, $claims);
        }

        $issuedMs = $claims['iatMs'] ?? 0;
        $elapsedMs = \max(0, (int) (\microtime(true) * 1000) - (\is_int($issuedMs) ? $issuedMs : 0));

        $error = null;
        try {
            $fresh = $this->nonces->withParams(self::placeholders($claims))->consume($nonce, $this->token->ttl);
        } catch (Throwable $e) {
            if (!$this->acceptWhenNoncesUnavailable) {
                return new Result(Status::Unavailable, $claims, $difficulty, $elapsedMs, $e);
            }
            // The replay guard did not run; the solution is still checked below.
            $fresh = true;
            $error = $e;
        }

        if (!$fresh) {
            return new Result(Status::Replay, $claims, $difficulty, $elapsedMs);
        }

        if (!self::meetsDifficulty($nonce, $answer, $difficulty)) {
            return new Result(Status::Rejected, $claims, $difficulty, $elapsedMs, $error);
        }

        return new Result(Status::Valid, $claims, $difficulty, $elapsedMs, $error);
    }

    /**
     * Below 0 every solution passes; above the digest width none can.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertDifficulty(int $difficulty): void
    {
        if ($difficulty < 0 || $difficulty > self::MAX_DIFFICULTY) {
            throw new \InvalidArgumentException('difficulty must be between 0 and ' . self::MAX_DIFFICULTY . ', got ' . $difficulty);
        }
    }

    /**
     * True when SHA-256(nonce ":" solution) has at least `difficulty` leading
     * zero bits. One hash — the same rule on client and server.
     */
    public static function meetsDifficulty(string $nonce, string $solution, int $difficulty): bool
    {
        return self::leadingZeroBits(\hash('sha256', $nonce . ':' . $solution, true)) >= $difficulty;
    }

    /**
     * Count leading zero bits across a binary digest, most significant bit
     * first — the order a browser counts in, so difficulty means the same on
     * both sides.
     */
    public static function leadingZeroBits(string $digest): int
    {
        $bits = 0;
        $length = \strlen($digest);

        for ($i = 0; $i < $length; $i++) {
            $byte = \ord($digest[$i]);
            if ($byte === 0) {
                $bits += 8;

                continue;
            }

            for ($mask = 0x80; $mask > 0; $mask >>= 1) {
                if (($byte & $mask) !== 0) {
                    return $bits;
                }
                $bits++;
            }
        }

        return $bits;
    }

    /**
     * Nonce key placeholders from the token's claims: "{name}" => value.
     *
     * @param  array<mixed>  $claims
     * @return array<string, string>
     */
    private static function placeholders(array $claims): array
    {
        $placeholders = [];
        foreach ($claims as $name => $value) {
            if (\is_string($value) || \is_int($value)) {
                $placeholders['{' . $name . '}'] = (string) $value;
            }
        }

        return $placeholders;
    }
}
