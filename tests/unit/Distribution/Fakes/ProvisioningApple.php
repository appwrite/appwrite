<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Utopia\Distribution\Credential;
use Utopia\Distribution\Exception\Conflict;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Exception\NotFound;
use Utopia\Distribution\Exception\Unavailable;
use Utopia\Distribution\Signing;
use Utopia\Distribution\Store\Apple;
use Utopia\Distribution\Store\Apple\Bundle;
use Utopia\Distribution\Store\Apple\Certificate;
use Utopia\Distribution\Store\Apple\Profile;
use Utopia\Distribution\Tests\Transport;

/**
 * App Store Connect provisioning held in memory: certificates are issued from
 * the request by a throwaway authority and profiles are real signed
 * envelopes, so the code under test parses what it would parse from Apple.
 */
final class ProvisioningApple extends Apple
{
    public const string TEAM = 'ABCDE12345';

    private const string DATE = 'Y-m-d\TH:i:s\Z';

    private const int DAYS = 365;

    /**
     * @var list<string>
     */
    public array $calls = [];

    /**
     * @var list<string>
     */
    public array $requests = [];

    /**
     * @var list<string>
     */
    public array $createdBundles = [];

    /**
     * @var array<string, Certificate>
     */
    public array $certificates = [];

    /**
     * @var array<string, Profile>
     */
    public array $profiles = [];

    /**
     * @var array<string, list<string>>
     */
    public array $profileCertificates = [];

    /**
     * @var array<string, Bundle>
     */
    public array $bundles = [];

    /**
     * Days until a profile for the bundle identifier expires; others expire after a year.
     *
     * @var array<string, int>
     */
    public array $profileDays = [];

    public int $certificateDays = self::DAYS;

    public bool $certificateLimit = false;

    public bool $duplicateBundle = false;

    /**
     * Fails every deleteProfile and deleteCertificate call.
     */
    public bool $failDeletes = false;

    /**
     * Bytes of padding added to every profile, to make the envelopes as large as a test needs.
     */
    public int $profileBytes = 0;

    /**
     * Fails the createProfile call with this number, counted from the first call.
     */
    public ?int $failProfileAfter = null;

    public int $profileCalls = 0;

    private int $sequence = 0;

    private static ?string $authorityKey = null;

    private static ?string $authority = null;

    public function __construct()
    {
        $key = \openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $privateKey = null;

        if ($key === false || !\openssl_pkey_export($key, $privateKey) || !\is_string($privateKey)) {
            throw new \RuntimeException('Fake App Store Connect key could not be created');
        }

        parent::__construct(new Credential\Apple('KEY1234567', 'issuer-1', $privateKey), new Transport());
    }

    public function seedBundle(string $identifier, string $seed = self::TEAM): Bundle
    {
        return $this->bundles[$identifier] = new Bundle('bundle-' . ++$this->sequence, $identifier, 'Example', Bundle::PLATFORM_IOS, $seed, []);
    }

    #[\Override]
    public function createCertificate(string $csr, string $type = Certificate::TYPE_DISTRIBUTION): Certificate
    {
        $this->calls[] = 'createCertificate';
        $this->requests[] = $csr;

        if ($this->certificateLimit) {
            throw new Conflict('App Store Connect returned 409 ENTITY_ERROR.ATTRIBUTE.INVALID: You already have a current Distribution certificate or a pending certificate request.');
        }

        [$authorityKey, $authority] = self::authority();
        $signed = \openssl_csr_sign($csr, $authority, $authorityKey, $this->certificateDays, ['digest_alg' => 'sha256', 'config' => Signing\Certificate::CONFIG], \random_int(1, PHP_INT_MAX));
        $pem = null;

        if ($signed === false || !\openssl_x509_export($signed, $pem) || !\is_string($pem)) {
            throw new \RuntimeException('Fake certificate could not be signed');
        }

        $certificate = new Signing\Certificate($pem);
        $id = 'certificate-' . ++$this->sequence;

        return $this->certificates[$id] = new Certificate($id, $type, (string) $this->sequence, $certificate->expiresAt(), $certificate);
    }

    #[\Override]
    public function deleteCertificate(string $certificateId): void
    {
        $this->calls[] = 'deleteCertificate';

        if ($this->failDeletes) {
            throw new Unavailable('App Store Connect returned 500');
        }

        if (!isset($this->certificates[$certificateId])) {
            throw new NotFound("App Store Connect has no certificate \"{$certificateId}\"");
        }

        unset($this->certificates[$certificateId]);

        foreach ($this->profileCertificates as $profileId => $certificateIds) {
            if (\in_array($certificateId, $certificateIds, true)) {
                $profile = $this->profiles[$profileId];
                $this->profiles[$profileId] = new Profile($profile->id, $profile->name, $profile->type, Profile::STATE_INVALID, $profile->uuid, $profile->expiresAt, $profile->content);
            }
        }
    }

    #[\Override]
    public function getBundle(string $identifier): Bundle
    {
        $this->calls[] = 'getBundle';

        return $this->bundles[$identifier] ?? throw new NotFound("App Store Connect has no bundle id \"{$identifier}\"");
    }

    #[\Override]
    public function createBundle(string $identifier, string $name, string $platform = Bundle::PLATFORM_IOS): Bundle
    {
        $this->calls[] = 'createBundle';

        if ($this->duplicateBundle) {
            $this->seedBundle($identifier);

            throw new Invalid('App Store Connect returned 409 ENTITY_ERROR.ATTRIBUTE.INVALID.DUPLICATE: An App ID with Identifier is not available.');
        }

        $this->createdBundles[] = $identifier;

        return $this->bundles[$identifier] = new Bundle('bundle-' . ++$this->sequence, $identifier, $name, $platform, self::TEAM, []);
    }

    #[\Override]
    public function createProfile(string $name, string $type, string $bundleId, array $certificateIds): Profile
    {
        $this->calls[] = 'createProfile';
        $this->profileCalls++;

        if ($this->profileCalls === $this->failProfileAfter) {
            throw new Unavailable('App Store Connect returned 500');
        }

        $bundle = \array_find($this->bundles, static fn (Bundle $bundle): bool => $bundle->id === $bundleId) ?? throw new Invalid("The bundle ID '{$bundleId}' does not exist.");
        $certificates = \array_map(fn (string $certificateId): Certificate => $this->certificates[$certificateId] ?? throw new Invalid("The certificate '{$certificateId}' does not exist."), $certificateIds);

        $id = 'profile-' . ++$this->sequence;
        $uuid = \sprintf('00000000-0000-4000-8000-%012d', $this->sequence);
        $expiresAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->modify('+' . ($this->profileDays[$bundle->identifier] ?? self::DAYS) . ' days');

        $this->profileCertificates[$id] = $certificateIds;

        return $this->profiles[$id] = new Profile($id, $name, $type, Profile::STATE_ACTIVE, $uuid, $expiresAt, $this->envelope($this->plist($name, $uuid, $bundle, $certificates, $expiresAt)));
    }

    #[\Override]
    public function deleteProfile(string $profileId): void
    {
        $this->calls[] = 'deleteProfile';

        if ($this->failDeletes) {
            throw new Unavailable('App Store Connect returned 500');
        }

        if (!isset($this->profiles[$profileId])) {
            throw new NotFound("App Store Connect has no profile \"{$profileId}\"");
        }

        unset($this->profiles[$profileId], $this->profileCertificates[$profileId]);
    }

    /**
     * @return list<string>
     */
    public function activeProfiles(): array
    {
        return \array_keys(\array_filter($this->profiles, static fn (Profile $profile): bool => $profile->state === Profile::STATE_ACTIVE));
    }

    /**
     * @param list<Certificate> $certificates
     */
    private function plist(string $name, string $uuid, Bundle $bundle, array $certificates, \DateTimeImmutable $expiresAt): string
    {
        $data = \implode('', \array_map(static fn (Certificate $certificate): string => '<data>' . \base64_encode($certificate->certificate->der()) . '</data>', $certificates));
        $team = $bundle->seed;

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<plist version="1.0"><dict>'
            . '<key>ApplicationIdentifierPrefix</key><array><string>' . $team . '</string></array>'
            . '<key>DeveloperCertificates</key><array>' . $data . '</array>'
            . '<key>Entitlements</key><dict>'
            . '<key>application-identifier</key><string>' . $team . '.' . \htmlspecialchars($bundle->identifier, ENT_XML1) . '</string>'
            . '<key>com.apple.developer.team-identifier</key><string>' . $team . '</string>'
            . '<key>get-task-allow</key><false/>'
            . '</dict>'
            . '<key>ExpirationDate</key><date>' . $expiresAt->format(self::DATE) . '</date>'
            . '<key>Name</key><string>' . \htmlspecialchars($name, ENT_XML1) . '</string>'
            . '<key>Padding</key><string>' . \str_repeat('a', $this->profileBytes) . '</string>'
            . '<key>TeamIdentifier</key><array><string>' . $team . '</string></array>'
            . '<key>UUID</key><string>' . $uuid . '</string>'
            . '</dict></plist>' . "\n";
    }

    private function envelope(string $plist): string
    {
        [$authorityKey, $authority] = self::authority();
        $input = \tempnam(\sys_get_temp_dir(), 'plist');
        $output = \tempnam(\sys_get_temp_dir(), 'profile');

        try {
            if ($input === false || $output === false || \file_put_contents($input, $plist) !== \strlen($plist)) {
                throw new \RuntimeException('Fake profile could not be written');
            }

            if (!\openssl_cms_sign($input, $output, $authority, $authorityKey, null, OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER)) {
                throw new \RuntimeException('Fake profile could not be signed');
            }

            $envelope = \file_get_contents($output);

            return $envelope === false ? throw new \RuntimeException('Fake profile could not be read') : $envelope;
        } finally {
            foreach ([$input, $output] as $path) {
                if (\is_string($path) && \is_file($path)) {
                    \unlink($path);
                }
            }
        }
    }

    /**
     * @return array{string, string}
     */
    private static function authority(): array
    {
        if (self::$authorityKey === null || self::$authority === null) {
            $key = Signing\Certificate::key();
            $signed = \openssl_csr_sign(Signing\Certificate::request($key, ['commonName' => 'Fake Apple Worldwide Developer Relations CA']), null, $key, 3650, ['digest_alg' => 'sha256', 'config' => Signing\Certificate::CONFIG], 1);
            $pem = null;

            if ($signed === false || !\openssl_x509_export($signed, $pem) || !\is_string($pem)) {
                throw new \RuntimeException('Fake authority could not be created');
            }

            self::$authorityKey = $key;
            self::$authority = $pem;
        }

        return [self::$authorityKey, self::$authority];
    }
}
