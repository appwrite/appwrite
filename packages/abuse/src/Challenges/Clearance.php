<?php

namespace Utopia\Abuse\Challenges;

use Ahc\Jwt\JWT;
use InvalidArgumentException;
use Throwable;

/**
 * The stateless clearance credential: a signed JWT issued
 * after a successful challenge that lets later requests skip it. The binding to
 * (projectId, resourceId, coarse IP prefix, UA hash) is subtractive — it can only make a
 * copied cookie fail elsewhere, never grant passage to a client with no cookie.
 * It also records the solved difficulty and issue time, so a rule only accepts a
 * clearance at least as hard as its own and younger than its own clearance TTL.
 * Verifying it is pure crypto, so the hot path touches no Redis.
 */
final readonly class Clearance
{
    public const string COOKIE_NAME = '__aw_clr';

    /** Lifetime in seconds (~30 min). Short on purpose — tolerates IP drift. */
    public const int DEFAULT_TTL = 1800;

    /** HMAC label deriving this token's signing key from the configured secret. */
    public const string KEY_LABEL = 'edge-waf-clearance';

    public const string PURPOSE_CLAIM = 'purpose';

    /** Purpose claim value; keeps a challenge token from verifying as a clearance. */
    public const string PURPOSE = 'clearance';

    private JWT $jwt;

    public function __construct(string $secret)
    {
        if ($secret === '') {
            throw new InvalidArgumentException('Clearance requires a non-empty signing secret.');
        }
        $this->jwt = new JWT(\hash_hmac('sha256', self::KEY_LABEL, $secret), 'HS256', self::DEFAULT_TTL, 0);
    }

    /**
     * Mint a clearance cookie value bound to the solving client's identity. The
     * enforced binding is (projectId, resourceId, ipPrefix, uaHash) — see verify().
     * An empty resourceId only matches an empty resourceId. $difficulty is the
     * difficulty that was solved; $ttl sets the cookie's own expiry.
     */
    public function issue(string $projectId, string $resourceId, string $ipPrefix, string $uaHash, int $difficulty, int $ttl = self::DEFAULT_TTL): string
    {
        $ttl = $ttl > 0 ? $ttl : self::DEFAULT_TTL;
        $now = \time();

        return $this->jwt->encode([
            self::PURPOSE_CLAIM => self::PURPOSE,
            'projectId' => $projectId,
            'resourceId' => $resourceId,
            'ipPrefix' => $ipPrefix,
            'uaHash' => $uaHash,
            'difficulty' => $difficulty,
            // Not `iat`: Ahc\Jwt would expire the token at iat + its fixed maxAge
            'issuedAt' => $now,
            'exp' => $now + $ttl,
            // 'ja4' => null, // reserved binding slot for a future TLS fingerprint
        ]);
    }

    /**
     * True when the cookie is a valid, unexpired clearance whose binding matches
     * the current connection, whose solved difficulty is at least $difficulty, and
     * which was issued less than $ttl seconds ago (the default TTL when $ttl <= 0).
     * The cookie's own expiry still applies. A present-but-mismatched cookie
     * returns false so the caller re-challenges (never a hard block — a legit IP
     * change mid-session must not lock a user out).
     */
    public function verify(string $cookie, string $projectId, string $resourceId, string $ipPrefix, string $uaHash, int $difficulty, int $ttl): bool
    {
        if ($cookie === '') {
            return false;
        }

        try {
            $claims = $this->jwt->decode($cookie);
        } catch (Throwable) {
            return false;
        }

        // Cookies minted without these claims never verify; visitors may be challenged again until the rollout completes
        $solvedDifficulty = $claims['difficulty'] ?? null;
        $issuedAt = $claims['issuedAt'] ?? null;
        if (!\is_string($claims['resourceId'] ?? null) || !\is_int($solvedDifficulty) || !\is_int($issuedAt)) {
            return false;
        }

        $ttl = $ttl > 0 ? $ttl : self::DEFAULT_TTL;

        return ($claims[self::PURPOSE_CLAIM] ?? null) === self::PURPOSE
            && ($claims['projectId'] ?? null) === $projectId
            && $claims['resourceId'] === $resourceId
            && ($claims['ipPrefix'] ?? null) === $ipPrefix
            && ($claims['uaHash'] ?? null) === $uaHash
            && $solvedDifficulty >= $difficulty
            && $issuedAt + $ttl > \time();
    }
}
