<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests\Store;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Distribution\Credential;
use Utopia\Distribution\Exception\Conflict;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Exception\NotFound;
use Utopia\Distribution\Exception\Unauthorized;
use Utopia\Distribution\Exception\Unavailable;
use Utopia\Distribution\Signing;
use Utopia\Distribution\Store\Apple;
use Utopia\Distribution\Store\Apple\Bundle;
use Utopia\Distribution\Store\Apple\Certificate;
use Utopia\Distribution\Store\Apple\Profile;
use Utopia\Distribution\Tests\Store\Apple\Frozen;
use Utopia\Distribution\Tests\Transport;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class AppleProvisioningTest extends TestCase
{
    private const string KEY_ID = 'ABC123DEFG';

    private const string ISSUER_ID = '57246542-96fe-1a63-e053-0824d011072a';

    private const string TEAM = 'ABCDE12345';

    private const string IDENTIFIER = 'io.example.app';

    private const string BUNDLE_ID = 'J4KDF83G2H';

    private const string CERTIFICATE_ID = 'Q8R7S6T5U4';

    private const string PROFILE_ID = 'Z9Y8X7W6V5';

    private const string PROFILE_UUID = '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0';

    private const string SERIAL = '6C0B2A1E9F8D7C6B';

    private const string EXPIRATION = '2027-11-17T06:16:26.000+0000';

    private const int NOW = 1_795_000_000;

    private const string ENDPOINT = 'https://store.test/apple';

    private static string $privateKey = '';

    private static string $key = '';

    private static string $request = '';

    private static Signing\Certificate $identity;

    private static string $envelope = '';

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        self::assertTrue(openssl_pkey_export($key, $private));
        self::assertIsString($private);
        self::$privateKey = $private;

        self::$key = Signing\Certificate::key();
        self::$request = Signing\Certificate::request(self::$key, ['commonName' => 'Appwrite Distribution']);
        self::$identity = self::sign(self::$request, self::$key, 365);

        $authorityKey = Signing\Certificate::key();
        $authority = self::sign(Signing\Certificate::request($authorityKey, ['commonName' => 'Apple Provisioning Profile Signing']), $authorityKey, 3650);
        self::$envelope = self::envelope(self::plist(), $authority, $authorityKey);
    }

    public function testListCertificatesFiltersByTypeAndDecodesContent(): void
    {
        $transport = new Transport($this->reply(['data' => [$this->certificateResource(), $this->certificateResource('A1B2C3D4E5', 'IOS_DISTRIBUTION')]]));

        $certificates = $this->store($transport)->listCertificates();

        $request = $this->only($transport);
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/certificates', $this->path($request));
        $this->assertSame(['filter' => ['certificateType' => 'DISTRIBUTION'], 'limit' => '200'], $this->query($request));
        $this->assertCount(2, $certificates);
        $this->assertSame(self::CERTIFICATE_ID, $certificates[0]->id);
        $this->assertSame(Certificate::TYPE_DISTRIBUTION, $certificates[0]->type);
        $this->assertSame(self::SERIAL, $certificates[0]->serial);
        $this->assertEquals(new \DateTimeImmutable(self::EXPIRATION), $certificates[0]->expiresAt);
        $this->assertSame(self::$identity->fingerprint(), $certificates[0]->certificate->fingerprint());
        $this->assertSame(self::$identity->der(), $certificates[0]->certificate->der());
        $this->assertSame('A1B2C3D4E5', $certificates[1]->id);
        $this->assertSame(Certificate::TYPE_IOS_DISTRIBUTION, $certificates[1]->type);
    }

    public function testListCertificatesSendsChosenType(): void
    {
        $transport = new Transport($this->reply(['data' => []]));

        $this->assertSame([], $this->store($transport)->listCertificates(Certificate::TYPE_DEVELOPMENT));
        $this->assertSame(['filter' => ['certificateType' => 'DEVELOPMENT'], 'limit' => '200'], $this->query($this->only($transport)));
    }

    public function testCreateCertificatePostsRequestAndReturnsPemCertificate(): void
    {
        $transport = new Transport($this->reply(['data' => $this->certificateResource()], 201));

        $certificate = $this->store($transport)->createCertificate(self::$request);

        $request = $this->only($transport);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/certificates', (string) $request->getUri());
        $this->assertSame([
            'data' => [
                'type' => 'certificates',
                'attributes' => [
                    'certificateType' => 'DISTRIBUTION',
                    'csrContent' => self::$request,
                ],
            ],
        ], $this->body($request));
        $this->assertSame(self::CERTIFICATE_ID, $certificate->id);
        $this->assertStringStartsWith("-----BEGIN CERTIFICATE-----\n", $certificate->certificate->pem);
        $this->assertSame(self::$identity->fingerprint(), $certificate->certificate->fingerprint());
        $this->assertSame(self::$identity->subject(), $certificate->certificate->subject());
        $this->assertNotSame('', self::$identity->export(self::$key, 'secret', 'Appwrite'));
    }

    public function testCreateCertificateWithoutExpirationUsesCertificateExpiry(): void
    {
        $resource = $this->certificateResource();
        unset($resource['attributes']['expirationDate']);

        $certificate = $this->store(new Transport($this->reply(['data' => $resource], 201)))->createCertificate(self::$request);

        $this->assertEquals(self::$identity->expiresAt(), $certificate->expiresAt);
    }

    public function testCreateCertificateAboveTeamLimitThrowsConflict(): void
    {
        $transport = new Transport($this->error(409, 'ENTITY_ERROR.ATTRIBUTE.INVALID', 'There is a problem with the request entity', 'You already have a current Distribution certificate or a pending certificate request.'));

        try {
            $this->store($transport)->createCertificate(self::$request);
            $this->fail('createCertificate should throw');
        } catch (Conflict $conflict) {
            $this->assertSame('ENTITY_ERROR.ATTRIBUTE.INVALID', $conflict->getIssues()[0]->code);
            $this->assertInstanceOf(Invalid::class, $conflict->getPrevious());
            $this->assertNotNull($conflict->getBody());
        }

        $this->assertCount(1, $transport->requests());
    }

    public function testCreateCertificateOtherEntityErrorStaysInvalid(): void
    {
        $this->expectException(Invalid::class);

        $this->store(new Transport($this->error(409, 'ENTITY_ERROR.ATTRIBUTE.INVALID', 'An attribute value is invalid.', 'The CSR content is not valid.')))
            ->createCertificate(self::$request);
    }

    public function testCreateCertificateForbiddenThrowsUnauthorized(): void
    {
        try {
            $this->store(new Transport($this->error(403, 'FORBIDDEN_ERROR', 'This request is forbidden for security reasons', 'You are not allowed to perform this operation.')))
                ->createCertificate(self::$request);
            $this->fail('createCertificate should throw');
        } catch (Unauthorized $unauthorized) {
            $this->assertFalse($unauthorized->isCredential());
            $this->assertSame('FORBIDDEN_ERROR', $unauthorized->getIssues()[0]->code);
            $this->assertSame('You are not allowed to perform this operation.', $unauthorized->getIssues()[0]->message);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedCertificates(): iterable
    {
        yield 'not base64' => ['!!!'];
        yield 'empty' => [''];
        yield 'not a certificate' => [base64_encode('not a certificate')];
    }

    #[DataProvider('malformedCertificates')]
    public function testCreateCertificateWithMalformedContentThrowsUnavailable(string $content): void
    {
        $resource = $this->certificateResource();
        $resource['attributes']['certificateContent'] = $content;

        $this->expectException(Unavailable::class);

        $this->store(new Transport($this->reply(['data' => $resource], 201)))->createCertificate(self::$request);
    }

    public function testDeleteCertificateRevokesById(): void
    {
        $transport = new Transport($this->page(204, ''));

        $this->store($transport)->deleteCertificate(self::CERTIFICATE_ID);

        $request = $this->only($transport);
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/certificates/' . self::CERTIFICATE_ID, (string) $request->getUri());
    }

    public function testGetBundleMatchesIdentifierExactlyAmongPrefixMatches(): void
    {
        $transport = new Transport($this->reply([
            'data' => [
                $this->bundleResource('W1W2W3W4W5', self::IDENTIFIER . '.widget', ['W1W2W3W4W5_APP_GROUPS']),
                $this->bundleResource(self::BUNDLE_ID, self::IDENTIFIER, [self::BUNDLE_ID . '_PUSH_NOTIFICATIONS', self::BUNDLE_ID . '_ASSOCIATED_DOMAINS']),
            ],
            'included' => [
                $this->capabilityResource('W1W2W3W4W5_APP_GROUPS', 'APP_GROUPS'),
                $this->capabilityResource(self::BUNDLE_ID . '_PUSH_NOTIFICATIONS', 'PUSH_NOTIFICATIONS'),
                $this->capabilityResource(self::BUNDLE_ID . '_ASSOCIATED_DOMAINS', 'ASSOCIATED_DOMAINS'),
            ],
        ]));

        $bundle = $this->store($transport)->getBundle(self::IDENTIFIER);

        $request = $this->only($transport);
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/bundleIds', $this->path($request));
        $this->assertSame([
            'filter' => ['identifier' => self::IDENTIFIER],
            'include' => 'bundleIdCapabilities',
            'limit' => '200',
        ], $this->query($request));
        $this->assertSame(self::BUNDLE_ID, $bundle->id);
        $this->assertSame(self::IDENTIFIER, $bundle->identifier);
        $this->assertSame('Example', $bundle->name);
        $this->assertSame(Bundle::PLATFORM_IOS, $bundle->platform);
        $this->assertSame(self::TEAM, $bundle->seed);
        $this->assertSame([Bundle::CAPABILITY_PUSH_NOTIFICATIONS, Bundle::CAPABILITY_ASSOCIATED_DOMAINS], $bundle->capabilities);
    }

    public function testGetBundleWithOnlyPrefixMatchesThrowsNotFound(): void
    {
        $this->expectException(NotFound::class);

        $this->store(new Transport($this->reply(['data' => [$this->bundleResource('W1W2W3W4W5', self::IDENTIFIER . '.widget')]])))
            ->getBundle(self::IDENTIFIER);
    }

    public function testCreateBundlePostsAttributesAndExposesSeed(): void
    {
        $transport = new Transport($this->reply(['data' => $this->bundleResource(self::BUNDLE_ID, self::IDENTIFIER)], 201));

        $bundle = $this->store($transport)->createBundle(self::IDENTIFIER, 'Example');

        $request = $this->only($transport);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/bundleIds', (string) $request->getUri());
        $this->assertSame([
            'data' => [
                'type' => 'bundleIds',
                'attributes' => [
                    'identifier' => self::IDENTIFIER,
                    'name' => 'Example',
                    'platform' => 'IOS',
                ],
            ],
        ], $this->body($request));
        $this->assertSame(self::BUNDLE_ID, $bundle->id);
        $this->assertSame(self::TEAM, $bundle->seed);
        $this->assertSame([], $bundle->capabilities);
    }

    public function testCreateBundleWithoutSeedThrowsUnavailable(): void
    {
        $resource = $this->bundleResource(self::BUNDLE_ID, self::IDENTIFIER);
        unset($resource['attributes']['seedId']);

        $this->expectException(Unavailable::class);

        $this->store(new Transport($this->reply(['data' => $resource], 201)))->createBundle(self::IDENTIFIER, 'Example');
    }

    public function testCreateCapabilityPostsTypeAndBundleRelationship(): void
    {
        $transport = new Transport($this->reply(['data' => $this->capabilityResource(self::BUNDLE_ID . '_PUSH_NOTIFICATIONS', 'PUSH_NOTIFICATIONS')], 201));

        $this->store($transport)->createCapability(self::BUNDLE_ID, Bundle::CAPABILITY_PUSH_NOTIFICATIONS);

        $request = $this->only($transport);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/bundleIdCapabilities', (string) $request->getUri());
        $this->assertSame([
            'data' => [
                'type' => 'bundleIdCapabilities',
                'attributes' => ['capabilityType' => 'PUSH_NOTIFICATIONS'],
                'relationships' => [
                    'bundleId' => ['data' => ['type' => 'bundleIds', 'id' => self::BUNDLE_ID]],
                ],
            ],
        ], $this->body($request));
    }

    public function testCreateCapabilityIgnoresDuplicate(): void
    {
        $transport = new Transport($this->error(409, 'ENTITY_ERROR.ATTRIBUTE.INVALID.DUPLICATE', 'The provided entity includes an attribute with an invalid value', 'The capability PUSH_NOTIFICATIONS is already enabled for this bundle id.'));

        $this->store($transport)->createCapability(self::BUNDLE_ID, Bundle::CAPABILITY_PUSH_NOTIFICATIONS);

        $this->assertCount(1, $transport->requests());
    }

    public function testCreateCapabilityOtherConflictThrowsInvalid(): void
    {
        $this->expectException(Invalid::class);

        $this->store(new Transport($this->error(409, 'ENTITY_ERROR.ATTRIBUTE.INVALID', 'An attribute value is invalid.', 'The capability type is not valid.')))
            ->createCapability(self::BUNDLE_ID, 'TELEPORTATION');
    }

    public function testListProfilesReadsTheBundleProfiles(): void
    {
        $transport = new Transport($this->reply(['data' => [$this->profileResource()]]));

        $profiles = $this->store($transport)->listProfiles(self::BUNDLE_ID);

        $request = $this->only($transport);
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/bundleIds/' . self::BUNDLE_ID . '/profiles?limit=200', (string) $request->getUri());
        $this->assertCount(1, $profiles);
        $this->assertSame(self::PROFILE_ID, $profiles[0]->id);
        $this->assertSame(Profile::TYPE_IOS_APP_STORE, $profiles[0]->type);
        $this->assertSame(Profile::STATE_ACTIVE, $profiles[0]->state);
        $this->assertSame(self::$envelope, $profiles[0]->content);
    }

    public function testCreateProfilePostsRelationshipsAndDecodesParseableContent(): void
    {
        $transport = new Transport($this->reply(['data' => $this->profileResource()], 201));

        $profile = $this->store($transport)->createProfile('Appwrite io.example.app App Store', Profile::TYPE_IOS_APP_STORE, self::BUNDLE_ID, [self::CERTIFICATE_ID, 'A1B2C3D4E5']);

        $request = $this->only($transport);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/profiles', (string) $request->getUri());
        $this->assertSame([
            'data' => [
                'type' => 'profiles',
                'attributes' => [
                    'name' => 'Appwrite io.example.app App Store',
                    'profileType' => 'IOS_APP_STORE',
                ],
                'relationships' => [
                    'bundleId' => ['data' => ['type' => 'bundleIds', 'id' => self::BUNDLE_ID]],
                    'certificates' => ['data' => [
                        ['type' => 'certificates', 'id' => self::CERTIFICATE_ID],
                        ['type' => 'certificates', 'id' => 'A1B2C3D4E5'],
                    ]],
                ],
            ],
        ], $this->body($request));
        $this->assertSame(self::PROFILE_ID, $profile->id);
        $this->assertSame('Appwrite io.example.app App Store', $profile->name);
        $this->assertSame(self::PROFILE_UUID, $profile->uuid);
        $this->assertEquals(new \DateTimeImmutable(self::EXPIRATION), $profile->expiresAt);

        $parsed = Signing\Profile::parse($profile->content);
        $this->assertSame(Signing\Profile::TYPE_APP_STORE, $parsed->type);
        $this->assertSame(self::TEAM, $parsed->team);
        $this->assertSame(self::IDENTIFIER, $parsed->identifier);
        $this->assertSame(self::PROFILE_UUID, $parsed->uuid);
        $this->assertTrue($parsed->contains(self::$identity));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function malformedProfiles(): iterable
    {
        yield 'not base64' => ['%%%'];
        yield 'missing' => [null];
    }

    #[DataProvider('malformedProfiles')]
    public function testCreateProfileWithMalformedContentThrowsUnavailable(?string $content): void
    {
        $resource = $this->profileResource();
        unset($resource['attributes']['profileContent']);
        if ($content !== null) {
            $resource['attributes']['profileContent'] = $content;
        }

        $this->expectException(Unavailable::class);

        $this->store(new Transport($this->reply(['data' => $resource], 201)))->createProfile('Name', Profile::TYPE_IOS_APP_STORE, self::BUNDLE_ID, [self::CERTIFICATE_ID]);
    }

    public function testCreateProfileWithMalformedExpirationThrowsUnavailable(): void
    {
        $resource = $this->profileResource();
        $resource['attributes']['expirationDate'] = '2027-13-45T99:99:99';

        $this->expectException(Unavailable::class);

        $this->store(new Transport($this->reply(['data' => $resource], 201)))->createProfile('Name', Profile::TYPE_IOS_APP_STORE, self::BUNDLE_ID, [self::CERTIFICATE_ID]);
    }

    public function testDeleteProfileDeletesById(): void
    {
        $transport = new Transport($this->page(204, ''));

        $this->store($transport)->deleteProfile(self::PROFILE_ID);

        $request = $this->only($transport);
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/profiles/' . self::PROFILE_ID, (string) $request->getUri());
    }

    /**
     * @return iterable<string, array{\Closure(Apple): mixed}>
     */
    public static function incomplete(): iterable
    {
        yield 'certificate type' => [static fn (Apple $store): array => $store->listCertificates('')];
        yield 'certificate request' => [static fn (Apple $store): Certificate => $store->createCertificate(' ')];
        yield 'certificate id' => [static fn (Apple $store) => $store->deleteCertificate('')];
        yield 'bundle identifier' => [static fn (Apple $store): Bundle => $store->getBundle('')];
        yield 'bundle name' => [static fn (Apple $store): Bundle => $store->createBundle(self::IDENTIFIER, '')];
        yield 'capability bundle' => [static fn (Apple $store) => $store->createCapability('', Bundle::CAPABILITY_PUSH_NOTIFICATIONS)];
        yield 'capability type' => [static fn (Apple $store) => $store->createCapability(self::BUNDLE_ID, '')];
        yield 'profiles bundle' => [static fn (Apple $store): array => $store->listProfiles('')];
        yield 'profile certificates' => [static fn (Apple $store): Profile => $store->createProfile('Name', Profile::TYPE_IOS_APP_STORE, self::BUNDLE_ID, [])];
        yield 'profile certificate id' => [static fn (Apple $store): Profile => $store->createProfile('Name', Profile::TYPE_IOS_APP_STORE, self::BUNDLE_ID, [''])];
        yield 'profile id' => [static fn (Apple $store) => $store->deleteProfile('')];
    }

    /**
     * @param \Closure(Apple): mixed $operation
     */
    #[DataProvider('incomplete')]
    public function testMissingValueIsRejectedBeforeSending(\Closure $operation): void
    {
        $transport = new Transport();

        try {
            $operation($this->store($transport));
            $this->fail('operation should throw');
        } catch (Invalid) {
            $this->assertSame([], $transport->requests());
        }
    }

    /**
     * @return array{type: string, id: string, attributes: array<string, mixed>}
     */
    private function certificateResource(string $id = self::CERTIFICATE_ID, string $type = Certificate::TYPE_DISTRIBUTION): array
    {
        return [
            'type' => 'certificates',
            'id' => $id,
            'attributes' => [
                'name' => 'Apple Distribution: Example',
                'displayName' => 'Example',
                'certificateType' => $type,
                'serialNumber' => self::SERIAL,
                'platform' => null,
                'expirationDate' => self::EXPIRATION,
                'certificateContent' => base64_encode(self::$identity->der()),
                'activated' => true,
            ],
        ];
    }

    /**
     * @param list<string> $capabilities
     *
     * @return array{type: string, id: string, attributes: array<string, mixed>, relationships: array<string, mixed>}
     */
    private function bundleResource(string $id, string $identifier, array $capabilities = []): array
    {
        return [
            'type' => 'bundleIds',
            'id' => $id,
            'attributes' => [
                'name' => 'Example',
                'platform' => 'IOS',
                'identifier' => $identifier,
                'seedId' => self::TEAM,
            ],
            'relationships' => [
                'bundleIdCapabilities' => [
                    'data' => array_map(static fn (string $capability): array => ['type' => 'bundleIdCapabilities', 'id' => $capability], $capabilities),
                ],
            ],
        ];
    }

    /**
     * @return array{type: string, id: string, attributes: array<string, mixed>}
     */
    private function capabilityResource(string $id, string $type): array
    {
        return [
            'type' => 'bundleIdCapabilities',
            'id' => $id,
            'attributes' => ['capabilityType' => $type, 'settings' => []],
        ];
    }

    /**
     * @return array{type: string, id: string, attributes: array<string, mixed>}
     */
    private function profileResource(): array
    {
        return [
            'type' => 'profiles',
            'id' => self::PROFILE_ID,
            'attributes' => [
                'name' => 'Appwrite io.example.app App Store',
                'platform' => 'IOS',
                'profileType' => Profile::TYPE_IOS_APP_STORE,
                'profileState' => Profile::STATE_ACTIVE,
                'profileContent' => base64_encode(self::$envelope),
                'uuid' => self::PROFILE_UUID,
                'createdDate' => '2026-11-17T06:16:26.000+0000',
                'expirationDate' => self::EXPIRATION,
            ],
        ];
    }

    private static function sign(string $request, string $key, int $days): Signing\Certificate
    {
        $signed = openssl_csr_sign($request, null, $key, $days, ['digest_alg' => 'sha256', 'config' => Signing\Certificate::CONFIG], random_int(1, PHP_INT_MAX));
        self::assertNotFalse($signed);

        $pem = null;
        self::assertTrue(openssl_x509_export($signed, $pem));
        self::assertIsString($pem);

        return new Signing\Certificate($pem);
    }

    private static function plist(): string
    {
        $certificate = base64_encode(self::$identity->der());
        $team = self::TEAM;
        $identifier = self::IDENTIFIER;
        $uuid = self::PROFILE_UUID;

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <plist version="1.0">
            <dict>
            	<key>AppIDName</key><string>Example</string>
            	<key>ApplicationIdentifierPrefix</key><array><string>{$team}</string></array>
            	<key>DeveloperCertificates</key><array><data>{$certificate}</data></array>
            	<key>Entitlements</key><dict>
            		<key>application-identifier</key><string>{$team}.{$identifier}</string>
            		<key>com.apple.developer.team-identifier</key><string>{$team}</string>
            		<key>get-task-allow</key><false/>
            	</dict>
            	<key>ExpirationDate</key><date>2027-11-17T06:16:26Z</date>
            	<key>Name</key><string>Appwrite io.example.app App Store</string>
            	<key>TeamIdentifier</key><array><string>{$team}</string></array>
            	<key>UUID</key><string>{$uuid}</string>
            </dict>
            </plist>

            XML;
    }

    private static function envelope(string $plist, Signing\Certificate $signer, string $key): string
    {
        $input = tempnam(sys_get_temp_dir(), 'plist');
        $output = tempnam(sys_get_temp_dir(), 'envelope');
        self::assertIsString($input);
        self::assertIsString($output);

        try {
            file_put_contents($input, $plist);
            self::assertTrue(openssl_cms_sign($input, $output, $signer->pem, $key, null, OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER));

            $envelope = file_get_contents($output);
            self::assertIsString($envelope);

            return $envelope;
        } finally {
            unlink($input);
            unlink($output);
        }
    }

    private function store(Transport $transport): Frozen
    {
        return new Frozen(new Credential\Apple(self::KEY_ID, self::ISSUER_ID, self::$privateKey), $transport, self::NOW, self::ENDPOINT);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function reply(array $body, int $status = 200): ResponseInterface
    {
        return $this->page($status, json_encode($body, JSON_THROW_ON_ERROR))->withHeader('Content-Type', 'application/json');
    }

    private function error(int $status, string $code, string $title, string $detail): ResponseInterface
    {
        return $this->reply(['errors' => [[
            'id' => '0c5a5f53-6b3e-4b0e-9a63-6f2a2f1d0401',
            'status' => (string) $status,
            'code' => $code,
            'title' => $title,
            'detail' => $detail,
        ]]], $status);
    }

    private function page(int $status, string $body): ResponseInterface
    {
        return new Response($status, body: new Stream\Factory()->createStream($body))
            ->withHeader('X-Rate-Limit', 'user-hour-lim:3500;user-hour-rem:3499;');
    }

    private function only(Transport $transport): RequestInterface
    {
        $requests = $transport->requests();
        $this->assertCount(1, $requests);

        return $requests[0];
    }

    private function path(RequestInterface $request): string
    {
        return (string) $request->getUri()->withQuery('');
    }

    /**
     * @return array<mixed>
     */
    private function query(RequestInterface $request): array
    {
        parse_str($request->getUri()->getQuery(), $query);

        return $query;
    }

    /**
     * @return array<mixed>
     */
    private function body(RequestInterface $request): array
    {
        $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);

        return $body;
    }
}
