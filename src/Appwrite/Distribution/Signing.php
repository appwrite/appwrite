<?php

namespace Appwrite\Distribution;

use Utopia\Cache\Cache;
use Utopia\Database\Document;

/**
 * One-time tokens a sign job on a local device presents to fetch its signing
 * bundle. A token is bound to the app, the subject (a build, or the artifact of
 * an upload-sign) and the attempt, and is spent by the first redeem. Only its
 * SHA-256 is kept, so the cache never holds a usable token.
 */
readonly class Signing
{
    public const int TTL = 600;

    private const int BYTES = 32;

    public function __construct(private Cache $cache)
    {
    }

    public function issue(Document $project, string $appId, string $subjectId, int $attempt): string
    {
        $this->revoke($project, $subjectId, $attempt);

        $token = \bin2hex(\random_bytes(self::BYTES));
        $hash = \hash('sha256', $token);

        $this->cache->save($this->tokenKey($project, $hash), [
            'appId' => $appId,
            'subjectId' => $subjectId,
            'attempt' => $attempt,
        ], ttl: self::TTL);
        $this->cache->save($this->subjectKey($project, $subjectId, $attempt), $hash, ttl: self::TTL);

        return $token;
    }

    /**
     * Spends a token. Of concurrent redeems only the one whose purge removes the
     * entry gets the binding back.
     *
     * @return array{appId: string, subjectId: string, attempt: int}|null
     */
    public function redeem(Document $project, string $token): ?array
    {
        if (\strlen($token) !== self::BYTES * 2 || !\ctype_xdigit($token)) {
            return null;
        }

        $key = $this->tokenKey($project, \hash('sha256', \strtolower($token)));
        $binding = $this->cache->load($key, self::TTL);

        if (!\is_array($binding) || !$this->cache->purge($key)) {
            return null;
        }

        $subjectId = (string) ($binding['subjectId'] ?? '');
        $attempt = (int) ($binding['attempt'] ?? 0);
        $this->cache->purge($this->subjectKey($project, $subjectId, $attempt));

        return [
            'appId' => (string) ($binding['appId'] ?? ''),
            'subjectId' => $subjectId,
            'attempt' => $attempt,
        ];
    }

    public function revoke(Document $project, string $subjectId, int $attempt): void
    {
        $subjectKey = $this->subjectKey($project, $subjectId, $attempt);
        $hash = $this->cache->load($subjectKey, self::TTL);

        if (\is_string($hash) && $hash !== '') {
            $this->cache->purge($this->tokenKey($project, $hash));
        }

        $this->cache->purge($subjectKey);
    }

    /**
     * The bundle the sign job reads from `signing/bundle.json`, taken from an
     * Android signing identity's decrypted secret.
     *
     * @return array{keystore: string, storePassword: string, keyAlias: string, keyPassword: string}
     */
    public static function bundle(Document $signingIdentity): array
    {
        $secret = $signingIdentity->getAttribute('secret', []);
        if (!\is_array($secret)) {
            $secret = [];
        }

        return [
            'keystore' => (string) ($secret['keystore'] ?? ''),
            'storePassword' => (string) ($secret['storePassword'] ?? ''),
            'keyAlias' => (string) ($secret['keyAlias'] ?? ''),
            'keyPassword' => (string) ($secret['keyPassword'] ?? ''),
        ];
    }

    private function tokenKey(Document $project, string $hash): string
    {
        return "distribution-signing-{$project->getSequence()}-{$hash}";
    }

    private function subjectKey(Document $project, string $subjectId, int $attempt): string
    {
        return "distribution-signing-{$project->getSequence()}-subject-" . \hash('sha256', "{$subjectId}:{$attempt}");
    }
}
