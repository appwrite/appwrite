<?php

namespace Appwrite\Distribution;

use Appwrite\Distribution\Signing\Mode;
use Appwrite\Distribution\Signing\Status;
use Appwrite\Extend\Exception;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Distribution\Exception as StoreException;
use Utopia\Distribution\Exception\Conflict;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Exception\NotFound;
use Utopia\Distribution\Exception\RateLimited;
use Utopia\Distribution\Exception\Unauthorized;
use Utopia\Distribution\Exception\Unavailable;
use Utopia\Distribution\Signing;
use Utopia\Distribution\Store;
use Utopia\Distribution\Store\Apple\Bundle;
use Utopia\Distribution\Store\Apple\Profile;
use Utopia\Lock\Distributed;
use Utopia\Lock\Exception\Contention;

/**
 * Managed Apple signing. A project holds one distribution certificate per
 * Apple team, shared by every managed iOS identity of that team, and each
 * identity holds one App Store profile per bundle ID. Every method that
 * changes the team's certificates or profiles runs under `lock(teamId)` and
 * takes that lock: it refreshes the lease before each change at Apple and
 * before each write, and stops with a Contention once the lease is lost.
 *
 * Besides the files a build reads, a managed identity's secret keeps what
 * renewal and rotation need under `managed`: the Apple ids of its bundles
 * and profiles, each profile's expiry, the certificate's expiry, when its
 * profiles were last renewed, and when and why the last renewal failed.
 *
 * @phpstan-type Issued array{id: string, archive: string, password: string, fingerprint: string, certificate: Signing\Certificate}
 * @phpstan-type Managed array{bundles: array<string, string>, profiles: array<string, string>, expiries: array<string, string>, certificateExpiresAt: string|null, renewedAt: string|null, attemptedAt: string|null, failure: string|null}
 * @phpstan-type Secret array{certificate: string, password: string, profiles: array<string, string>, managed: Managed}
 */
readonly class Provisioning
{
    public const int RENEWAL = 2592000;

    public const int ACTIVE_MINUTES = 1440;

    public const int RETRY_MINUTES = 15;

    public const int RENEWING_MINUTES = 1;

    public const string PROFILE = 'Appwrite %s App Store %s';

    public const string ROTATED = 'Appwrite %s App Store %s %s';

    private const int COOLDOWN = 86400;

    private const int MINUTE = 60;

    private const string COLLECTION = 'distributionSigningIdentities';

    private const string LOCK = 'distribution:apple:team:';

    private const string COMMON_NAME = 'Appwrite Distribution';

    private const int PASSWORD_BYTES = 16;

    private const int SUFFIX_BYTES = 4;

    private const int SECRET_SIZE = 1_000_000;

    private const int ENCRYPTION_OVERHEAD = 256;

    private const int BASE64_BLOCK_BYTES = 3;

    private const int BASE64_BLOCK_CHARACTERS = 4;

    private const string LIMIT = 'Apple allows a limited number of distribution certificates per team and this team has reached it. Import an existing certificate instead, or revoke one in your Apple Developer account.';

    public function __construct(private Database $dbForProject)
    {
    }

    public static function lock(string $teamId): string
    {
        return self::LOCK . $teamId;
    }

    public static function status(\DateTimeImmutable $expiresAt, \DateTimeImmutable $now): Status
    {
        return match (true) {
            $expiresAt <= $now => Status::Expired,
            self::isExpiring($expiresAt, $now) => Status::Expiring,
            default => Status::Active,
        };
    }

    /**
     * Minutes until the identity's schedule row next runs, or 0 to stop it.
     * Only a managed iOS identity whose profiles, not its certificate, are
     * expiring or expired is renewed, at most once a day, until its
     * certificate expires. A failed renewal is retried after RETRY_MINUTES
     * when the store was unavailable, after a day otherwise.
     */
    public static function interval(Document $identity, \DateTimeImmutable $now): int
    {
        $expiresAt = self::date($identity->getAttribute('expiresAt'));

        if ($expiresAt === null || $identity->getAttribute('status') === Status::Revoked->value) {
            return 0;
        }

        if (!self::isManagedIos($identity)) {
            return $expiresAt <= $now ? 0 : self::ACTIVE_MINUTES;
        }

        $managed = self::secret($identity)['managed'];
        $certificateExpiresAt = self::date($managed['certificateExpiresAt']) ?? $expiresAt;

        if ($certificateExpiresAt <= $now) {
            return 0;
        }

        if (!self::isExpiring($expiresAt, $now) || self::isExpiring($certificateExpiresAt, $now)) {
            return self::ACTIVE_MINUTES;
        }

        $attemptedAt = self::date($managed['attemptedAt']);
        $backoff = $managed['failure'] === Exception::DISTRIBUTION_STORE_UNAVAILABLE ? self::RETRY_MINUTES : self::ACTIVE_MINUTES;

        if ($attemptedAt !== null && self::elapsed($attemptedAt, $now) < $backoff * self::MINUTE) {
            return $backoff;
        }

        $renewedAt = self::date($managed['renewedAt']);

        if ($renewedAt !== null && self::elapsed($renewedAt, $now) < self::COOLDOWN) {
            return self::ACTIVE_MINUTES;
        }

        return self::RENEWING_MINUTES;
    }

    public static function failure(StoreException $error): Exception
    {
        return match (true) {
            $error instanceof Unauthorized => new Exception(Exception::DISTRIBUTION_CONNECTION_INVALID, $error->getMessage(), previous: $error),
            $error instanceof Unavailable, $error instanceof RateLimited => new Exception(Exception::DISTRIBUTION_STORE_UNAVAILABLE, $error->getMessage(), previous: $error),
            default => new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, $error->getMessage(), previous: $error),
        };
    }

    /**
     * The bundle ID, created when Apple has none. Its `seed` is the team ID.
     */
    public function team(Store\Apple $store, string $identifier, string $name): Bundle
    {
        $bundle = self::bundle($store, $identifier);

        if ($bundle !== null) {
            return $bundle;
        }

        try {
            return $store->createBundle($identifier, $name);
        } catch (Conflict|Invalid $duplicate) {
            return self::bundle($store, $identifier) ?? throw $duplicate;
        }
    }

    /**
     * Creates a managed iOS identity, reusing the team's certificate when
     * another managed iOS identity of the team holds one. Whatever this call
     * created at Apple is removed again when it fails.
     *
     * @param list<string> $identifiers
     */
    public function create(Store\Apple $store, Distributed $lock, Document $connection, string $signingIdentityId, string $name, array $identifiers, string $teamId): Document
    {
        $now = new \DateTimeImmutable();
        $certificate = $this->shared($teamId, $now);
        $issued = null;
        $created = [];

        try {
            if ($certificate === null) {
                $certificate = $issued = $this->issue($store, $lock, $name);
            }

            $secret = self::blank($certificate);

            foreach ($identifiers as $identifier) {
                $bundle = $this->owned($store, $identifier, $name, $teamId);

                self::hold($lock);
                $profile = $store->createProfile(\sprintf(self::PROFILE, $identifier, $signingIdentityId), Profile::TYPE_IOS_APP_STORE, $bundle->id, [$certificate['id']]);
                $created[] = $profile->id;

                $secret = self::profile($secret, $identifier, $bundle->id, $profile, self::verify($profile, $certificate['certificate'], $teamId));
            }

            self::fits($secret, \count($identifiers));
            $expiresAt = self::expiry($certificate['certificate'], $secret);
            self::hold($lock);

            return $this->dbForProject->createDocument(self::COLLECTION, new Document([
                '$id' => $signingIdentityId,
                '$permissions' => [],
                'platform' => Platform::Ios->value,
                'name' => $name,
                'mode' => Mode::Managed->value,
                'secret' => $secret,
                'fingerprint' => $certificate['fingerprint'],
                'teamId' => $teamId,
                'identifiers' => $identifiers,
                'connectionId' => $connection->getId(),
                'connectionInternalId' => (string) $connection->getSequence(),
                'externalId' => $certificate['id'],
                'expiresAt' => self::format($expiresAt),
                'status' => self::status($expiresAt, $now)->value,
            ]));
        } catch (\Throwable $error) {
            $this->discard($store, $created, $issued === null ? [] : [$issued['id']]);

            throw $error;
        }
    }

    /**
     * Regenerates the identity's profiles that expire within the renewal
     * window. The new profiles get rotation-suffixed names and replace the
     * old ones only once all of them exist and the identity holds them; a
     * failure removes what this call created and leaves the identity as it
     * was. A certificate that is itself expiring is never regenerated here,
     * only flagged: replacing it is an explicit rotation.
     */
    public function renew(Store\Apple $store, Distributed $lock, Document $identity): Document
    {
        if (self::date($identity->getAttribute('expiresAt')) === null || !self::isManagedIos($identity)) {
            return $identity;
        }

        $now = new \DateTimeImmutable();
        $current = self::secret($identity);
        $certificate = Signing\Certificate::fromPkcs12((string) \base64_decode($current['certificate'], true), $current['password']);

        if (self::isExpiring($certificate->expiresAt(), $now)) {
            return $this->track($identity, $now);
        }

        $teamId = (string) $identity->getAttribute('teamId', '');
        $name = (string) $identity->getAttribute('name');
        $certificateId = (string) $identity->getAttribute('externalId', '');
        $suffix = \bin2hex(\random_bytes(self::SUFFIX_BYTES));
        $secret = $current;
        $created = [];
        $replaced = [];

        try {
            foreach ($identity->getAttribute('identifiers', []) as $identifier) {
                $profileExpiresAt = self::date($current['managed']['expiries'][$identifier] ?? null);

                if ($profileExpiresAt !== null && !self::isExpiring($profileExpiresAt, $now)) {
                    continue;
                }

                $bundleId = $current['managed']['bundles'][$identifier] ?? $this->owned($store, $identifier, $name, $teamId)->id;

                self::hold($lock);
                $profile = $store->createProfile(\sprintf(self::ROTATED, $identifier, $identity->getId(), $suffix), Profile::TYPE_IOS_APP_STORE, $bundleId, [$certificateId]);
                $created[] = $profile->id;

                $secret = self::profile($secret, $identifier, $bundleId, $profile, self::verify($profile, $certificate, $teamId));
                $replaced[] = $current['managed']['profiles'][$identifier] ?? '';
            }

            self::hold($lock);
        } catch (\Throwable $error) {
            $this->discard($store, $created, []);

            throw $error;
        }

        $renewed = $this->renewed($identity, $certificate, $secret, $now);
        $this->discard($store, \array_values(\array_filter($replaced)), []);

        return $renewed;
    }

    /**
     * Records a failed renewal so `interval()` backs off, as long as the
     * lease is still held: a write without it could replace the profiles
     * another holder just wrote.
     */
    public function fail(Distributed $lock, Document $identity, string $failure): Document
    {
        if (!$lock->refresh()) {
            return $identity;
        }

        $secret = self::secret($identity);
        $secret['managed']['attemptedAt'] = self::format(new \DateTimeImmutable());
        $secret['managed']['failure'] = $failure;

        return $this->dbForProject->updateDocument(self::COLLECTION, $identity->getId(), new Document([
            'secret' => $secret,
        ]));
    }

    /**
     * Writes the status the identity's expiry gives it now, when it changed.
     * A revoked identity keeps its status.
     */
    public function track(Document $identity, \DateTimeImmutable $now): Document
    {
        $expiresAt = self::date($identity->getAttribute('expiresAt'));

        if ($expiresAt === null || $identity->getAttribute('status') === Status::Revoked->value) {
            return $identity;
        }

        $status = self::status($expiresAt, $now)->value;

        if ($status === $identity->getAttribute('status')) {
            return $identity;
        }

        return $this->dbForProject->updateDocument(self::COLLECTION, $identity->getId(), new Document([
            'status' => $status,
        ]));
    }

    /**
     * Re-issues the team's certificate for every managed iOS identity of the
     * team. Nothing is written until every Apple call has succeeded, every
     * identity is written in one transaction, and the old certificate and
     * profiles are removed only once every identity holds the new ones, so a
     * failure at any step leaves each identity with a certificate Apple still
     * honours. A failure, the write's included, removes what the rotation
     * created at Apple unless an identity of the team already holds it.
     *
     * @return list<Document>
     */
    public function rotate(Store\Apple $store, Distributed $lock, Document $identity): array
    {
        $teamId = (string) $identity->getAttribute('teamId', '');

        if (!self::isManagedIos($identity) || $teamId === '') {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, 'Only a managed iOS signing identity can be rotated.');
        }

        $identities = $this->identities($teamId);

        if (!\in_array($identity->getId(), \array_map(static fn (Document $member): string => $member->getId(), $identities), true)) {
            $identities[] = $identity;
        }

        $suffix = \bin2hex(\random_bytes(self::SUFFIX_BYTES));
        $now = new \DateTimeImmutable();
        $issued = null;
        $created = [];
        $updates = [];

        try {
            $issued = $this->issue($store, $lock, (string) $identity->getAttribute('name'));

            foreach ($identities as $member) {
                $current = self::secret($member);
                $name = (string) $member->getAttribute('name');
                $identifiers = $member->getAttribute('identifiers', []);
                $secret = self::blank($issued);
                $secret['managed']['renewedAt'] = $current['managed']['renewedAt'];

                foreach ($identifiers as $identifier) {
                    $bundleId = $current['managed']['bundles'][$identifier] ?? $this->owned($store, $identifier, $name, $teamId)->id;

                    self::hold($lock);
                    $profile = $store->createProfile(\sprintf(self::ROTATED, $identifier, $member->getId(), $suffix), Profile::TYPE_IOS_APP_STORE, $bundleId, [$issued['id']]);
                    $created[] = $profile->id;

                    $secret = self::profile($secret, $identifier, $bundleId, $profile, self::verify($profile, $issued['certificate'], $teamId));
                }

                self::fits($secret, \count($identifiers));
                $expiresAt = self::expiry($issued['certificate'], $secret);

                $updates[$member->getId()] = new Document([
                    'secret' => $secret,
                    'fingerprint' => $issued['fingerprint'],
                    'externalId' => $issued['id'],
                    'expiresAt' => self::format($expiresAt),
                    'status' => self::status($expiresAt, $now)->value,
                ]);
            }

            self::hold($lock);
        } catch (\Throwable $error) {
            $this->discard($store, $created, $issued === null ? [] : [$issued['id']]);

            throw $error instanceof StoreException ? self::failure($error) : $error;
        }

        try {
            $rotated = $this->dbForProject->withTransaction(function () use ($updates): array {
                $rotated = [];

                foreach ($updates as $id => $update) {
                    $rotated[] = $this->dbForProject->updateDocument(self::COLLECTION, $id, $update);
                }

                return $rotated;
            });
        } catch (\Throwable $error) {
            if (!$this->held($teamId, $issued['id'])) {
                $this->discard($store, $created, [$issued['id']]);
            }

            throw $error;
        }

        $profiles = [];
        $certificates = [];

        foreach ($identities as $member) {
            \array_push($profiles, ...\array_values(self::secret($member)['managed']['profiles']));
            $certificates[] = (string) $member->getAttribute('externalId', '');
        }

        $certificates = \array_values(\array_diff(\array_unique(\array_filter($certificates)), [$issued['id']]));
        $this->discard($store, $profiles, $certificates);

        return $rotated;
    }

    /**
     * Removes what a deleted managed iOS identity held at Apple: its profiles
     * and, unless another managed iOS identity of the team holds it, its
     * certificate. Best effort, and nothing is removed once the lease is
     * lost. Returns whether everything is gone.
     */
    public function delete(Store\Apple $store, Distributed $lock, Document $identity): bool
    {
        if (!self::isManagedIos($identity)) {
            return true;
        }

        if (!$lock->refresh()) {
            return false;
        }

        $certificateId = (string) $identity->getAttribute('externalId', '');
        $shared = \array_any(
            $this->identities((string) $identity->getAttribute('teamId', '')),
            static fn (Document $member): bool => $member->getId() !== $identity->getId() && $member->getAttribute('externalId') === $certificateId,
        );

        return $this->discard($store, \array_values(self::secret($identity)['managed']['profiles']), $shared || $certificateId === '' ? [] : [$certificateId]);
    }

    /**
     * The certificate of the team's first managed iOS identity that holds an
     * unexpired one.
     *
     * @return Issued|null
     */
    private function shared(string $teamId, \DateTimeImmutable $now): ?array
    {
        foreach ($this->identities($teamId) as $identity) {
            $secret = self::secret($identity);
            $archive = \base64_decode($secret['certificate'], true);
            $certificateId = (string) $identity->getAttribute('externalId', '');

            if ($archive === false || $archive === '' || $certificateId === '') {
                continue;
            }

            $certificate = Signing\Certificate::fromPkcs12($archive, $secret['password']);

            if ($certificate->expiresAt() > $now) {
                return [
                    'id' => $certificateId,
                    'archive' => $archive,
                    'password' => $secret['password'],
                    'fingerprint' => $certificate->fingerprint(),
                    'certificate' => $certificate,
                ];
            }
        }

        return null;
    }

    /**
     * Whether a managed iOS identity of the team holds the certificate.
     */
    private function held(string $teamId, string $certificateId): bool
    {
        return \array_any(
            $this->identities($teamId),
            static fn (Document $member): bool => $member->getAttribute('externalId') === $certificateId,
        );
    }

    /**
     * @return Issued
     */
    private function issue(Store\Apple $store, Distributed $lock, string $name): array
    {
        $key = Signing\Certificate::key();
        self::hold($lock);

        try {
            $issued = $store->createCertificate(Signing\Certificate::request($key, ['commonName' => self::COMMON_NAME]));
        } catch (Conflict $limit) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, self::LIMIT, previous: $limit);
        }

        $password = \bin2hex(\random_bytes(self::PASSWORD_BYTES));

        return [
            'id' => $issued->id,
            'archive' => $issued->certificate->export($key, $password, $name),
            'password' => $password,
            'fingerprint' => $issued->certificate->fingerprint(),
            'certificate' => $issued->certificate,
        ];
    }

    /**
     * The bundle ID, which must belong to the team.
     */
    private function owned(Store\Apple $store, string $identifier, string $name, string $teamId): Bundle
    {
        $bundle = $this->team($store, $identifier, $name);

        if ($bundle->seed !== $teamId) {
            throw new Invalid("Bundle ID \"{$identifier}\" belongs to team {$bundle->seed}, not {$teamId}.");
        }

        return $bundle;
    }

    /**
     * @return list<Document>
     */
    private function identities(string $teamId): array
    {
        return $this->dbForProject->find(self::COLLECTION, [
            Query::equal('platform', [Platform::Ios->value]),
            Query::equal('mode', [Mode::Managed->value]),
            Query::equal('teamId', [$teamId]),
            Query::notEqual('status', Status::Revoked->value),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]);
    }

    /**
     * Returns whether everything is gone at Apple.
     *
     * @param list<string> $profiles
     * @param list<string> $certificates
     */
    private function discard(Store\Apple $store, array $profiles, array $certificates): bool
    {
        $removed = true;

        foreach ($profiles as $profileId) {
            try {
                $store->deleteProfile($profileId);
            } catch (NotFound) {
                // Already gone at Apple.
            } catch (StoreException) {
                $removed = false;
            }
        }

        foreach ($certificates as $certificateId) {
            try {
                $store->deleteCertificate($certificateId);
            } catch (NotFound) {
                // Already gone at Apple.
            } catch (StoreException) {
                $removed = false;
            }
        }

        return $removed;
    }

    /**
     * @param Secret $secret
     */
    private function renewed(Document $identity, Signing\Certificate $certificate, #[\SensitiveParameter] array $secret, \DateTimeImmutable $now): Document
    {
        $secret['managed']['renewedAt'] = self::format($now);
        $secret['managed']['attemptedAt'] = null;
        $secret['managed']['failure'] = null;
        $expiresAt = self::expiry($certificate, $secret);

        return $this->dbForProject->updateDocument(self::COLLECTION, $identity->getId(), new Document([
            'secret' => $secret,
            'expiresAt' => self::format($expiresAt),
            'status' => self::status($expiresAt, $now)->value,
        ]));
    }

    private static function hold(Distributed $lock): void
    {
        if (!$lock->refresh()) {
            throw new Contention('The Apple team lock expired before the signing change completed.');
        }
    }

    /**
     * The encrypt filter stores the JSON secret base64-encoded inside an
     * envelope, and the attribute holds at most SECRET_SIZE characters.
     *
     * @param Secret $secret
     */
    private static function fits(#[\SensitiveParameter] array $secret, int $identifiers): void
    {
        $encoded = \strlen((string) \json_encode($secret));
        $stored = self::BASE64_BLOCK_CHARACTERS * \intdiv($encoded + self::BASE64_BLOCK_BYTES - 1, self::BASE64_BLOCK_BYTES) + self::ENCRYPTION_OVERHEAD;

        if ($stored > self::SECRET_SIZE) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, "The certificate and App Store profiles of {$identifiers} bundle IDs are too large for one signing identity. Sign fewer bundle IDs per managed signing identity.");
        }
    }

    /**
     * @param Issued $certificate
     * @return Secret
     */
    private static function blank(#[\SensitiveParameter] array $certificate): array
    {
        return [
            'certificate' => \base64_encode($certificate['archive']),
            'password' => $certificate['password'],
            'profiles' => [],
            'managed' => [
                'bundles' => [],
                'profiles' => [],
                'expiries' => [],
                'certificateExpiresAt' => self::format($certificate['certificate']->expiresAt()),
                'renewedAt' => null,
                'attemptedAt' => null,
                'failure' => null,
            ],
        ];
    }

    /**
     * @param Secret $secret
     * @return Secret
     */
    private static function profile(#[\SensitiveParameter] array $secret, string $identifier, string $bundleId, Profile $profile, Signing\Profile $parsed): array
    {
        $secret['profiles'][$identifier] = \base64_encode($profile->content);
        $secret['managed']['bundles'][$identifier] = $bundleId;
        $secret['managed']['profiles'][$identifier] = $profile->id;
        $secret['managed']['expiries'][$identifier] = self::format($parsed->expiresAt);

        return $secret;
    }

    private static function verify(Profile $profile, Signing\Certificate $certificate, string $teamId): Signing\Profile
    {
        $parsed = Signing\Profile::parse($profile->content);

        if (!$parsed->contains($certificate) || $parsed->team !== $teamId) {
            throw new Invalid("App Store Connect returned profile \"{$profile->name}\" without the certificate of team {$teamId}.");
        }

        return $parsed;
    }

    /**
     * @param Secret $secret
     */
    private static function expiry(Signing\Certificate $certificate, #[\SensitiveParameter] array $secret): \DateTimeImmutable
    {
        $expiresAt = $certificate->expiresAt();

        foreach ($secret['managed']['expiries'] as $profileExpiresAt) {
            $expiresAt = \min($expiresAt, self::date($profileExpiresAt) ?? $expiresAt);
        }

        return $expiresAt;
    }

    /**
     * @return Secret
     */
    private static function secret(Document $identity): array
    {
        $secret = $identity->getAttribute('secret', []);
        $secret = \is_array($secret) ? $secret : [];
        $managed = \is_array($secret['managed'] ?? null) ? $secret['managed'] : [];

        return [
            'certificate' => (string) ($secret['certificate'] ?? ''),
            'password' => (string) ($secret['password'] ?? ''),
            'profiles' => \is_array($secret['profiles'] ?? null) ? $secret['profiles'] : [],
            'managed' => [
                'bundles' => \is_array($managed['bundles'] ?? null) ? $managed['bundles'] : [],
                'profiles' => \is_array($managed['profiles'] ?? null) ? $managed['profiles'] : [],
                'expiries' => \is_array($managed['expiries'] ?? null) ? $managed['expiries'] : [],
                'certificateExpiresAt' => \is_string($managed['certificateExpiresAt'] ?? null) ? $managed['certificateExpiresAt'] : null,
                'renewedAt' => \is_string($managed['renewedAt'] ?? null) ? $managed['renewedAt'] : null,
                'attemptedAt' => \is_string($managed['attemptedAt'] ?? null) ? $managed['attemptedAt'] : null,
                'failure' => \is_string($managed['failure'] ?? null) ? $managed['failure'] : null,
            ],
        ];
    }

    private static function bundle(Store\Apple $store, string $identifier): ?Bundle
    {
        try {
            return $store->getBundle($identifier);
        } catch (NotFound) {
            return null;
        }
    }

    private static function isManagedIos(Document $identity): bool
    {
        return $identity->getAttribute('platform') === Platform::Ios->value && $identity->getAttribute('mode') === Mode::Managed->value;
    }

    private static function isExpiring(\DateTimeImmutable $expiresAt, \DateTimeImmutable $now): bool
    {
        return self::elapsed($now, $expiresAt) <= self::RENEWAL;
    }

    /**
     * Seconds from $from to $to.
     */
    private static function elapsed(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return $to->getTimestamp() - $from->getTimestamp();
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        return \is_string($value) && $value !== '' ? new \DateTimeImmutable($value, new \DateTimeZone('UTC')) : null;
    }

    private static function format(\DateTimeImmutable $date): string
    {
        return DateTime::format(\DateTime::createFromImmutable($date->setTimezone(new \DateTimeZone('UTC'))));
    }
}
