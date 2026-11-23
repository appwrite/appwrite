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

    /** Puzzle lifetime in seconds; also how long a used nonce is remembered. */
    public const int DEFAULT_TTL = 120;

    private const array RESERVED_CLAIMS = ['nonce', 'difficulty', 'iatMs'];

    private Token $token;

    /** @var array<string, string> */
    private array $binding;

    /**
     * @param  string  $secret  Signing secret
     * @param  Nonce  $nonces  Remembers used nonces; key placeholders such as "{project}" are filled from the puzzle's claims
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
        int $ttl = self::DEFAULT_TTL,
        private bool $acceptWhenNoncesUnavailable = false,
        string $label = 'challenge',
        string $purpose = 'challenge',
    ) {
        $this->token = new Token($secret, $label, $purpose, $ttl);
        $this->binding = [];
    }

    /**
     * A copy bound to these fields, e.g. the client's IP prefix and User-Agent
     * hash. Puzzles it issues verify only with the same binding.
     *
     * @param  array<string, string>  $binding
     */
    public function withBinding(array $binding): static
    {
        return clone($this, ['binding' => [...$this->binding, ...$binding]]);
    }

    /**
     * Sign a puzzle bound to this binding. Claims are carried through and
     * returned by verify.
     *
     * @param  array<string, string|int>  $claims
     *
     * @throws \InvalidArgumentException
     */
    public function issue(int $difficulty, array $claims = []): Puzzle
    {
        // Below 0 every solution passes; above the digest width none can.
        if ($difficulty < 0 || $difficulty > self::MAX_DIFFICULTY) {
            throw new \InvalidArgumentException('difficulty must be between 0 and ' . self::MAX_DIFFICULTY . ', got ' . $difficulty);
        }

        foreach ([...\array_keys($this->binding), ...\array_keys($claims)] as $name) {
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
        ], binding: $this->binding);

        return new Puzzle($token, $nonce, $difficulty);
    }

    /**
     * Verify a solution. The binding must equal the one the puzzle was issued
     * with; it is checked before the nonce is used, so a puzzle presented by
     * another client never spends the owner's attempt.
     */
    public function verify(string $token, string $solution): Result
    {
        $claims = $this->token->verify($token);
        if ($claims === null) {
            return new Result(Status::InvalidToken);
        }

        if (!Token::isBoundTo($claims, $this->binding)) {
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
