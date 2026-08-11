<?php

namespace Appwrite\Auth\SAML;

use Utopia\Cache\Cache;

/**
 * Short-lived server-side records backing the SAML flow.
 *
 * SAML needs server-side state for two reasons that OAuth2 does not have:
 *
 *  - `RelayState` is capped at 80 bytes by the SAML binding spec, so the
 *    success/failure/token payload Appwrite carries in the OAuth2 `state`
 *    query parameter cannot round-trip through the identity provider. Only an
 *    opaque lookup token is sent, and the payload is stored here.
 *  - The assertion arrives as a multi-kilobyte POST body, while the shared
 *    session pipeline is reached through a GET redirect. The validated
 *    identity is stored here and exchanged for a short code, the same shape
 *    the OAuth2 pipeline already understands.
 *
 * Records are single-use: consume() deletes before returning, so a replayed
 * code or relay token finds nothing.
 */
class Ticket
{
    /**
     * Cache collection for pending authentication requests, keyed by the
     * opaque RelayState token.
     */
    public const string REQUESTS = 'samlRequests';

    /**
     * Cache collection for validated identities awaiting exchange, keyed by the
     * opaque code handed to the redirect route.
     */
    public const string IDENTITIES = 'samlIdentities';

    /**
     * Cache collection recording consumed assertion IDs, for replay rejection.
     */
    public const string ASSERTIONS = 'samlAssertions';

    /**
     * How long a sign-in may stay in flight, in seconds. Generous enough for a
     * user to authenticate and satisfy MFA at the identity provider.
     */
    public const int REQUEST_TTL = 600;

    /**
     * How long a validated identity may wait to be exchanged. Only one browser
     * redirect happens in between, so this is deliberately short.
     */
    public const int IDENTITY_TTL = 120;

    /**
     * How long a redemption may hold its lock, and how long a caller waits for
     * one. Both are short: the critical section is a cache read and write.
     */
    private const int LOCK_TTL = 10;
    private const float LOCK_TIMEOUT = 5.0;

    /**
     * @param Cache $cache
     * @param (callable(string, int, callable, float): mixed)|null $locks
     *        The `locks` resource. Redemptions are check-then-act sequences, so
     *        without it two concurrent requests can both pass a single-use
     *        check. Optional only so the protocol code stays unit-testable.
     */
    public function __construct(
        private readonly Cache $cache,
        private readonly mixed $locks = null,
    ) {
    }

    /**
     * Run a redemption under a lock keyed on the record, so the read and the
     * write that follows it cannot interleave with another request for the same
     * key.
     *
     * @param string $collection
     * @param string $key
     * @param callable(): mixed $callback
     *
     * @return mixed
     */
    private function exclusively(string $collection, string $key, callable $callback): mixed
    {
        if (!\is_callable($this->locks)) {
            return $callback();
        }

        return ($this->locks)('saml:' . $collection . ':' . $key, self::LOCK_TTL, $callback, self::LOCK_TIMEOUT);
    }

    /**
     * An unguessable token safe to place in a URL.
     *
     * @return string
     */
    public static function token(): string
    {
        return \bin2hex(\random_bytes(32));
    }

    /**
     * @param string $collection
     * @param string $key
     * @param array<string, mixed> $payload
     * @param int $ttl
     *
     * @return void
     */
    public function save(string $collection, string $key, array $payload, int $ttl): void
    {
        $this->cache->save($collection, ['payload' => $payload, 'expiresAt' => \time() + $ttl], $key);
    }

    /**
     * Read and delete a record.
     *
     * Deleting before returning is what makes these single-use: an assertion
     * replayed with the same code finds nothing on the second attempt.
     *
     * @param string $collection
     * @param string $key
     *
     * @return array<string, mixed>|null
     */
    public function consume(string $collection, string $key): ?array
    {
        // The read and the delete have to be one step. Two requests arriving
        // with the same relay token or exchange code would otherwise both load
        // the record before either deleted it, and both would be handed a
        // session.
        return $this->exclusively($collection, $key, function () use ($collection, $key): ?array {
            $record = $this->cache->load($collection, self::maxAge($collection), $key);

            $this->cache->purge($collection, $key);

            if (!\is_array($record) || !isset($record['payload'], $record['expiresAt'])) {
                return null;
            }

            if ($record['expiresAt'] < \time()) {
                return null;
            }

            return $record['payload'];
        });
    }

    /**
     * Record an assertion ID as seen, returning false when it was already
     * recorded.
     *
     * The identity cache record is single-use on its own, so this closes the
     * narrower window where the same assertion is POSTed to the ACS twice
     * before either exchange completes.
     *
     * @param string $assertionId
     * @param int $expiry Unix timestamp the assertion stops being valid.
     *
     * @return bool
     */
    public function claimAssertion(string $assertionId, int $expiry): bool
    {
        $key = \hash('sha256', $assertionId);

        // Same reasoning as consume(): checking whether the assertion has been
        // seen and recording that it has must not interleave, or two deliveries
        // of one assertion both conclude they are the first.
        return $this->exclusively(self::ASSERTIONS, $key, function () use ($key, $expiry): bool {
            $ttl = \max(1, $expiry - \time());
            $existing = $this->cache->load(self::ASSERTIONS, $ttl, $key);

            if (\is_array($existing) && ($existing['expiresAt'] ?? 0) >= \time()) {
                return false;
            }

            $this->cache->save(self::ASSERTIONS, ['payload' => true, 'expiresAt' => $expiry], $key);

            return true;
        });
    }

    /**
     * Cache::load takes the maximum age it is willing to serve; records carry
     * their own expiry, which is what is actually enforced.
     *
     * @param string $collection
     *
     * @return int
     */
    private static function maxAge(string $collection): int
    {
        return $collection === self::IDENTITIES ? self::IDENTITY_TTL : self::REQUEST_TTL;
    }
}
