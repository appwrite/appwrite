<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use Appwrite\Distribution\Provider;
use Appwrite\Distribution\Stores;
use Appwrite\Extend\Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Utopia\Database\Document;
use Utopia\Distribution\Store\Apple;
use Utopia\Distribution\Store\Google;
use Utopia\Distribution\Tests\Transport;
use Utopia\Psr7\Response;

final class StoresTest extends TestCase
{
    private const string APPLE_OVERRIDE = 'http://appwrite/v1/mock/distribution/apple';

    private const string GOOGLE_OVERRIDE = 'http://appwrite/v1/mock/distribution/google';

    private static string $applePrivateKey;

    private static string $googlePrivateKey;

    public static function setUpBeforeClass(): void
    {
        $apple = \openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $google = \openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($apple);
        self::assertNotFalse($google);
        self::assertTrue(\openssl_pkey_export($apple, $applePrivateKey));
        self::assertTrue(\openssl_pkey_export($google, $googlePrivateKey));

        self::$applePrivateKey = $applePrivateKey;
        self::$googlePrivateKey = $googlePrivateKey;
    }

    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function overrides(): \Iterator
    {
        yield 'apple' => [self::APPLE_OVERRIDE, ''];
        yield 'google' => ['', self::GOOGLE_OVERRIDE];
        yield 'both' => [self::APPLE_OVERRIDE, self::GOOGLE_OVERRIDE];
    }

    #[DataProvider('overrides')]
    public function testOverrideIsRefusedInProduction(string $apple, string $google): void
    {
        try {
            new Stores($apple, $google, true, new Transport());
            $this->fail('An endpoint override must not be accepted in production.');
        } catch (Exception $exception) {
            $this->assertSame(Exception::GENERAL_SERVER_ERROR, $exception->getType());
        }
    }

    public function testProductionWithoutOverridesUsesStoreEndpoints(): void
    {
        $transport = new Transport(Transport::fixture('Apple/apps'));
        $stores = new Stores(production: true, client: $transport);

        $stores->verify($stores->adapter(Provider::Apple, $this->apple()));

        $this->assertStringStartsWith(Apple::ENDPOINT . '/v1/apps', (string) $this->first($transport)->getUri());
    }

    public function testProductionConstructsDefaultClient(): void
    {
        $stores = new Stores();

        $this->assertInstanceOf(Google::class, $stores->adapter(Provider::Google, $this->google()));
    }

    public function testGoogleOverrideSendsTokenRequestToOverrideWithGoogleAudience(): void
    {
        $transport = new Transport(Transport::fixture('Google/token'));
        $stores = new Stores(googleEndpoint: self::GOOGLE_OVERRIDE, production: false, client: $transport);

        $account = $stores->verify($stores->adapter(Provider::Google, $this->google()));

        $request = $this->first($transport);
        $this->assertSame(self::GOOGLE_OVERRIDE . '/token', (string) $request->getUri());
        $this->assertSame(Google::TOKEN, $this->claims($request)['aud']);
        $this->assertSame('play@appwrite-test.iam.gserviceaccount.com', $account->id);
    }

    public function testGoogleDefaultsToGoogleTokenEndpoint(): void
    {
        $transport = new Transport(Transport::fixture('Google/token'));
        $stores = new Stores(production: false, client: $transport);

        $stores->verify($stores->adapter(Provider::Google, $this->google()));

        $this->assertSame(Google::TOKEN, (string) $this->first($transport)->getUri());
    }

    public function testAppleOverrideSendsRequestsToOverride(): void
    {
        $transport = new Transport(Transport::fixture('Apple/apps'));
        $stores = new Stores(appleEndpoint: self::APPLE_OVERRIDE, production: false, client: $transport);

        $account = $stores->verify($stores->adapter(Provider::Apple, $this->apple()));

        $this->assertStringStartsWith(self::APPLE_OVERRIDE . '/v1/apps', (string) $this->first($transport)->getUri());
        $this->assertSame('69a6de7e-0000-47e3-e053-5b8c7c11a4d1', $account->id);
    }

    public function testAppleOverrideWithTrailingSlashSendsRequestsToOverride(): void
    {
        $transport = new Transport(Transport::fixture('Apple/apps'));
        $stores = new Stores(appleEndpoint: self::APPLE_OVERRIDE . '/', production: false, client: $transport);

        $stores->verify($stores->adapter(Provider::Apple, $this->apple()));

        $this->assertStringStartsWith(self::APPLE_OVERRIDE . '/v1/apps', (string) $this->first($transport)->getUri());
    }

    public function testGoogleOverrideWithTrailingSlashSendsTokenRequestToOverride(): void
    {
        $transport = new Transport(Transport::fixture('Google/token'));
        $stores = new Stores(googleEndpoint: self::GOOGLE_OVERRIDE . '/', production: false, client: $transport);

        $stores->verify($stores->adapter(Provider::Google, $this->google()));

        $this->assertSame(self::GOOGLE_OVERRIDE . '/token', (string) $this->first($transport)->getUri());
    }

    public function testConnectionBuildsAdapterFromStoredCredentials(): void
    {
        $transport = new Transport(Transport::fixture('Google/token'));
        $stores = new Stores(googleEndpoint: self::GOOGLE_OVERRIDE, production: false, client: $transport);

        $store = $stores->connection(new Document([
            'provider' => Provider::Google->value,
            'credentials' => $this->google(),
        ]));

        $this->assertInstanceOf(Google::class, $store);
        $this->assertSame('play@appwrite-test.iam.gserviceaccount.com', $stores->verify($store)->id);
    }

    /**
     * @return \Iterator<string, array{Provider, array<string, mixed>}>
     */
    public static function incomplete(): \Iterator
    {
        yield 'apple without key id' => [Provider::Apple, ['issuerId' => 'issuer', 'privateKey' => '-----BEGIN PRIVATE KEY-----']];
        yield 'apple without private key' => [Provider::Apple, ['keyId' => 'ABC123DEFG', 'issuerId' => 'issuer']];
        yield 'apple with a key path' => [Provider::Apple, ['keyId' => 'ABC123DEFG', 'issuerId' => 'issuer', 'privateKey' => 'file:///etc/key.p8']];
        yield 'google without email' => [Provider::Google, ['privateKey' => '-----BEGIN PRIVATE KEY-----']];
        yield 'google without private key' => [Provider::Google, ['clientEmail' => 'play@appwrite-test.iam.gserviceaccount.com']];
        yield 'google with a numeric project' => [Provider::Google, ['clientEmail' => 'play@appwrite-test.iam.gserviceaccount.com', 'privateKey' => '-----BEGIN PRIVATE KEY-----', 'projectId' => 7]];
    }

    /**
     * @param array<string, mixed> $credentials
     */
    #[DataProvider('incomplete')]
    public function testIncompleteCredentialsAreInvalid(Provider $provider, array $credentials): void
    {
        $stores = new Stores(production: false, client: new Transport());

        try {
            $stores->adapter($provider, $credentials);
            $this->fail('Incomplete credentials must be rejected.');
        } catch (Exception $exception) {
            $this->assertSame(Exception::DISTRIBUTION_CONNECTION_INVALID, $exception->getType());
            $this->assertSame(400, $exception->getCode());
        }
    }

    public function testConnectionWithoutProviderIsInvalid(): void
    {
        $stores = new Stores(production: false, client: new Transport());

        $this->expectExceptionObject(new Exception(Exception::DISTRIBUTION_CONNECTION_INVALID, 'The connection has no usable store credentials.'));

        $stores->connection(new Document(['provider' => 'amazon', 'credentials' => []]));
    }

    public function testRejectedCredentialsAreInvalid(): void
    {
        $transport = new Transport(Transport::fixture('Apple/error-unauthorized', 401), Transport::fixture('Apple/error-unauthorized', 401));
        $stores = new Stores(production: false, client: $transport);

        try {
            $stores->verify($stores->adapter(Provider::Apple, $this->apple()));
            $this->fail('Credentials the store rejects must be reported as invalid.');
        } catch (Exception $exception) {
            $this->assertSame(Exception::DISTRIBUTION_CONNECTION_INVALID, $exception->getType());
            $this->assertSame(400, $exception->getCode());
        }
    }

    public function testUnavailableStoreIsReportedAsUnavailable(): void
    {
        $transport = new Transport(new Response(503));
        $stores = new Stores(production: false, client: $transport);

        try {
            $stores->verify($stores->adapter(Provider::Google, $this->google()));
            $this->fail('A store that does not answer must be reported as unavailable.');
        } catch (Exception $exception) {
            $this->assertSame(Exception::DISTRIBUTION_STORE_UNAVAILABLE, $exception->getType());
            $this->assertSame(502, $exception->getCode());
        }
    }

    public function testRateLimitedStoreIsReportedAsUnavailable(): void
    {
        $transport = new Transport(new Response(429));
        $stores = new Stores(production: false, client: $transport);

        try {
            $stores->verify($stores->adapter(Provider::Google, $this->google()));
            $this->fail('A rate limited store must be reported as unavailable.');
        } catch (Exception $exception) {
            $this->assertSame(Exception::DISTRIBUTION_STORE_UNAVAILABLE, $exception->getType());
        }
    }

    /**
     * @return array<string, string>
     */
    private function apple(): array
    {
        return [
            'keyId' => 'ABC123DEFG',
            'issuerId' => '69a6de7e-0000-47e3-e053-5b8c7c11a4d1',
            'privateKey' => self::$applePrivateKey,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function google(): array
    {
        return [
            'clientEmail' => 'play@appwrite-test.iam.gserviceaccount.com',
            'privateKey' => self::$googlePrivateKey,
            'projectId' => 'appwrite-test',
        ];
    }

    private function first(Transport $transport): RequestInterface
    {
        $requests = $transport->requests();
        $this->assertNotEmpty($requests);

        return $requests[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function claims(RequestInterface $request): array
    {
        \parse_str((string) $request->getBody(), $form);
        $assertion = $form['assertion'] ?? null;
        $this->assertIsString($assertion);

        $parts = \explode('.', $assertion);
        $this->assertCount(3, $parts);

        $claims = \json_decode((string) \base64_decode(\strtr($parts[1], '-_', '+/')), true);
        $this->assertIsArray($claims);

        return $claims;
    }
}
