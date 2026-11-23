<?php

namespace Utopia\Abuse\Challenges;

use Ahc\Jwt\JWT;
use InvalidArgumentException;
use Throwable;
use Utopia\Abuse\Challenges\ProofOfWork\Nonce\Consumer;
use Utopia\Abuse\Challenges\ProofOfWork\Nonce\Outcome;
use Utopia\Abuse\Challenges\ProofOfWork\Result;
use Utopia\Abuse\Challenges\ProofOfWork\Status;

/**
 * Leading-zero-bits proof-of-work challenge tokens.
 *
 * Issue mints a signed, self-authenticating JWT (nonce, difficulty, and the
 * identity binding projectId/IP prefix/UA hash) — no server-side write. Verify
 * validates signature + expiry + binding, consumes the nonce once before the
 * solution check (so one challenge buys one attempt), and fails open on the
 * nonce if the store is unreachable.
 */
final readonly class ProofOfWork
{
    /**
     * Default leading-zero-*bits* difficulty. 20 bits ≈ 1M hashes ≈ ~5-10s in a
     * browser Web Worker; each +1 bit doubles the work. Per-client cost comes
     * from the binding, not the PoW.
     */
    public const int DEFAULT_DIFFICULTY = 20;

    /** Token lifetime in seconds; also the nonce single-use window (TTL == exp). */
    public const int DEFAULT_TTL = 120;

    /** HMAC label deriving this token's signing key from the configured secret. */
    public const string KEY_LABEL = 'edge-waf-challenge';

    public const string PURPOSE_CLAIM = 'purpose';

    /** Purpose claim value; keeps a clearance cookie from verifying as a challenge. */
    public const string PURPOSE = 'challenge';

    /**
     * Operator-facing difficulty level (1 easiest … 5 hardest) → leading-zero bits.
     */
    public const array LEVEL_BITS = [1 => 14, 2 => 16, 3 => 18, 4 => 20, 5 => 21];

    private JWT $jwt;

    public function __construct(
        string $secret,
        private Consumer $nonces,
        private int $ttl = self::DEFAULT_TTL,
    ) {
        if ($secret === '') {
            throw new InvalidArgumentException('ProofOfWork requires a non-empty signing secret.');
        }
        // maxAge == ttl: Ahc\Jwt stamps `exp` and rejects expired tokens on decode.
        $this->jwt = new JWT(\hash_hmac('sha256', self::KEY_LABEL, $secret), 'HS256', $this->ttl, 0);
    }

    /**
     * Mint a challenge token plus the fields the interstitial needs to solve it.
     *
     * @return array{token: string, nonce: string, difficulty: int}
     */
    public function issue(string $projectId, string $ipPrefix, string $uaHash, int $difficulty = self::DEFAULT_DIFFICULTY, int $clearanceTtl = 0, string $resourceId = ''): array
    {
        $nonce = \bin2hex(\random_bytes(16));
        $claims = [
            self::PURPOSE_CLAIM => self::PURPOSE,
            'nonce' => $nonce,
            'difficulty' => $difficulty,
            'projectId' => $projectId,
            'resourceId' => $resourceId,
            'ipPrefix' => $ipPrefix,
            'uaHash' => $uaHash,
            // custom iatMs as jwt iat issue time in seconds.
            'iatMs' => (int) (\microtime(true) * 1000),
            // 'ja4' => null, // reserved binding slot for a future TLS fingerprint
        ];
        if ($clearanceTtl > 0) {
            $claims['clr'] = $clearanceTtl;
        }

        $token = $this->jwt->encode($claims);

        return ['token' => $token, 'nonce' => $nonce, 'difficulty' => $difficulty];
    }

    /**
     * Translate an operator-facing difficulty level (1–5) to leading-zero bits.
     * An unknown/out-of-range level falls back to the default difficulty.
     */
    public static function bitsForLevel(int $level): int
    {
        return self::LEVEL_BITS[$level] ?? self::DEFAULT_DIFFICULTY;
    }

    /**
     * Verify a submitted solution against a token. Binding is checked against the
     * connection-derived identity (IP prefix + UA hash); the projectId comes from
     * the signed token. The nonce is burned before the solution check, so a bad
     * attempt still spends the challenge.
     */
    public function verify(string $token, string $solution, string $ipPrefix, string $uaHash): Result
    {
        try {
            $claims = $this->jwt->decode($token);
        } catch (Throwable) {
            return new Result(Status::InvalidToken, '');
        }

        if (($claims[self::PURPOSE_CLAIM] ?? null) !== self::PURPOSE) {
            return new Result(Status::InvalidToken, '');
        }

        // Binding checked before burning, so an attacker can only burn a nonce
        // inside their own self-issued token.
        if (self::stringClaim($claims, 'ipPrefix') !== $ipPrefix
            || self::stringClaim($claims, 'uaHash') !== $uaHash) {
            return new Result(Status::BindingMismatch, '');
        }

        $projectId = self::stringClaim($claims, 'projectId');
        $resourceId = self::stringClaim($claims, 'resourceId');
        $nonce = self::stringClaim($claims, 'nonce');
        if ($nonce === '') {
            return new Result(Status::InvalidToken, $projectId, $resourceId);
        }
        $difficulty = self::intClaim($claims, 'difficulty', self::DEFAULT_DIFFICULTY);
        $clearanceTtl = self::intClaim($claims, 'clr');

        $issuedMs = self::intClaim($claims, 'iatMs');
        $elapsedMs = \max(0, (int) (\microtime(true) * 1000) - $issuedMs);

        // Burn the nonce before the solution check. A replay is rejected; a store
        // outage fails open (validates the solution anyway). See Outcome.
        if ($this->nonces->consume($projectId, $nonce, $this->ttl) === Outcome::Consumed) {
            return new Result(Status::Replay, $projectId, $resourceId);
        }

        if (!self::meetsDifficulty($nonce, $solution, $difficulty)) {
            return new Result(Status::InvalidSolution, $projectId, $resourceId);
        }

        return new Result(Status::Valid, $projectId, $resourceId, $clearanceTtl, $elapsedMs, $difficulty);
    }

    /**
     * True when SHA-256(nonce ":" solution) has at least `difficulty` leading
     * zero bits. One hash, O(1) — identical rule on both client and server.
     */
    public static function meetsDifficulty(string $nonce, string $solution, int $difficulty): bool
    {
        $digest = \hash('sha256', $nonce . ':' . $solution, true); // raw bytes

        return self::leadingZeroBits($digest) >= $difficulty;
    }

    /**
     * Count leading zero bits across a binary digest, MSB-first — matching the
     * bit order the browser counts, so difficulty means the same on both sides.
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
     * @param array<mixed> $claims
     */
    private static function stringClaim(array $claims, string $name): string
    {
        $value = $claims[$name] ?? '';

        return \is_string($value) ? $value : '';
    }

    /**
     * @param array<mixed> $claims
     */
    private static function intClaim(array $claims, string $name, int $default = 0): int
    {
        $value = $claims[$name] ?? $default;

        return \is_int($value) ? $value : $default;
    }
}
