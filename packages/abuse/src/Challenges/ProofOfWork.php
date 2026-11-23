<?php

namespace Utopia\Abuse\Challenges;

use Throwable;
use Utopia\Abuse\Challenges\ProofOfWork\Puzzle;
use Utopia\Abuse\Challenges\ProofOfWork\Result;
use Utopia\Abuse\Challenges\ProofOfWork\Status;

/**
 * Leading-zero-bits proof-of-work. A client must find a solution whose
 * SHA-256(nonce ":" solution) starts with at least `difficulty` zero bits.
 *
 * Issue signs the nonce, difficulty, binding and claims into a token, with no
 * server-side write. Verify checks the token and binding, uses the nonce once
 * before checking the solution (so one puzzle buys one attempt), then checks the
 * solution.
 */
final readonly class ProofOfWork
{
    /** SHA-256 digest width: the most leading zero bits a solution can have. */
    public const int MAX_DIFFICULTY = 256;

    private const array RESERVED_CLAIMS = ['nonce', 'difficulty', 'iatMs'];

    /**
     * @param  Token  $token  Signs puzzles; its ttl is also how long a nonce stays used
     * @param  Nonce  $nonces  Key placeholders are filled from the token's claims, e.g. "pow:{project}"
     * @param  bool  $acceptWhenNoncesUnavailable  When the nonce store fails, check the solution anyway instead of rejecting
     */
    public function __construct(
        private Token $token,
        private Nonce $nonces,
        private bool $acceptWhenNoncesUnavailable = false,
    ) {
    }

    /**
     * Sign a puzzle. Verify must pass the same binding, every field of it;
     * claims are carried through and returned by verify.
     *
     * @param  array<string, string>  $binding
     * @param  array<string, string|int>  $claims
     *
     * @throws \InvalidArgumentException
     */
    public function issue(int $difficulty, array $binding = [], array $claims = []): Puzzle
    {
        // Below 0 every solution passes; above the digest width none can.
        if ($difficulty < 0 || $difficulty > self::MAX_DIFFICULTY) {
            throw new \InvalidArgumentException('difficulty must be between 0 and ' . self::MAX_DIFFICULTY . ', got ' . $difficulty);
        }

        foreach ([...\array_keys($binding), ...\array_keys($claims)] as $name) {
            if (\in_array($name, self::RESERVED_CLAIMS, true)) {
                throw new \InvalidArgumentException("claim '{$name}' is reserved");
            }
        }

        $nonce = \bin2hex(\random_bytes(16));
        $token = $this->token->issue([
            'nonce' => $nonce,
            'difficulty' => $difficulty,
            ...$claims,
            'iatMs' => (int) (\microtime(true) * 1000),
        ], binding: $binding);

        return new Puzzle($token, $nonce, $difficulty);
    }

    /**
     * Verify a solution. The binding (the same fields as at issue) is checked
     * before the nonce is used, so a token presented by another client never
     * spends the owner's attempt.
     *
     * @param  array<string, string>  $binding
     */
    public function verify(string $token, string $solution, array $binding = []): Result
    {
        $claims = $this->token->verify($token);
        if ($claims === null) {
            return new Result(Status::InvalidToken);
        }

        if (!Token::isBoundTo($claims, $binding)) {
            return new Result(Status::BindingMismatch);
        }

        $nonce = $claims['nonce'] ?? null;
        $difficulty = $claims['difficulty'] ?? null;
        if (!\is_string($nonce) || $nonce === '' || !\is_int($difficulty) || $difficulty < 0 || $difficulty > self::MAX_DIFFICULTY) {
            return new Result(Status::InvalidToken, $claims);
        }

        $issuedMs = $claims['iatMs'] ?? 0;
        $elapsedMs = \max(0, (int) (\microtime(true) * 1000) - (\is_int($issuedMs) ? $issuedMs : 0));

        $nonceError = null;
        try {
            $fresh = $this->nonces->withParams(self::placeholders($claims))->consume($nonce, $this->token->ttl);
        } catch (Throwable $error) {
            if (!$this->acceptWhenNoncesUnavailable) {
                return new Result(Status::NonceUnavailable, $claims, $difficulty, $elapsedMs, $error);
            }
            // The replay guard did not run; the solution is still checked below.
            $fresh = true;
            $nonceError = $error;
        }

        if (!$fresh) {
            return new Result(Status::Replay, $claims, $difficulty, $elapsedMs);
        }

        if (!self::meetsDifficulty($nonce, $solution, $difficulty)) {
            return new Result(Status::InvalidSolution, $claims, $difficulty, $elapsedMs, $nonceError);
        }

        return new Result(Status::Valid, $claims, $difficulty, $elapsedMs, $nonceError);
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
