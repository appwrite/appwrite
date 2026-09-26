<?php

declare(strict_types=1);

namespace Utopia\Tests\Registrar;

use Utopia\Cache\Adapter\None as NoneAdapter;
use Utopia\Cache\Cache as UtopiaCache;
use Utopia\Domains\Cache;
use Utopia\Domains\Registrar;
use Utopia\Domains\Registrar\Adapter\OpenSRS;
use Utopia\Domains\Registrar\Exception\AuthException;
use Utopia\Domains\Registrar\Exception\DomainNotTransferableException;
use Utopia\Domains\Registrar\UpdateDetails;

final class OpenSRSTest extends Base
{
    private Registrar $registrar;
    private Registrar $registrarWithCache;
    private string $key;
    private string $username;
    private string $testDomain = 'kffsfudlvc.net';

    protected function setUp(): void
    {
        $key = getenv('OPENSRS_KEY');
        $username = getenv('OPENSRS_USERNAME');
        if ($key === false || $key === '' || $username === false || $username === '') {
            $this->markTestSkipped('OPENSRS_KEY and OPENSRS_USERNAME are required');
        }

        $this->key = $key;
        $this->username = $username;
        $utopiaCache = new UtopiaCache(new NoneAdapter());
        $cache = new Cache($utopiaCache);

        $adapter = new OpenSRS(
            $key,
            $username,
            $this->generateRandomString(),
        );

        $this->registrar = new Registrar(
            $adapter,
            [
                'ns1.systemdns.com',
                'ns2.systemdns.com',
            ],
        );

        $this->registrarWithCache = new Registrar(
            $adapter,
            [
                'ns1.systemdns.com',
                'ns2.systemdns.com',
            ],
            $cache,
        );
    }

    protected function getRegistrar(): Registrar
    {
        return $this->registrar;
    }

    protected function getRegistrarWithCache(): Registrar
    {
        return $this->registrarWithCache;
    }

    protected function getTestDomain(): string
    {
        return $this->testDomain;
    }

    protected function getExpectedAdapterName(): string
    {
        return 'opensrs';
    }

    #[\Override]
    protected function getDefaultTld(): string
    {
        return 'net';
    }

    #[\Override]
    protected function getDefaultNameservers(): array
    {
        return [
            'ns1.systemdns.com',
            'ns2.systemdns.com',
        ];
    }

    protected function getUpdateDetails(?bool $autoRenew = null): UpdateDetails
    {
        return new UpdateDetails($autoRenew);
    }

    // OpenSRS-specific tests

    public function testPurchaseWithInvalidPassword(): void
    {
        $adapter = new OpenSRS(
            $this->key,
            $this->username,
            'password',
        );

        $registrar = new Registrar(
            $adapter,
            [
                'ns1.systemdns.com',
                'ns2.systemdns.com',
            ],
        );

        $domain = $this->generateRandomString() . '.net';
        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Failed to purchase domain: Invalid password');
        $registrar->purchase($domain, $this->getPurchaseContact(), 1);
    }

    public function testSuggestWithMultipleKeywords(): void
    {
        // Test suggestion domains only with prices
        $result = $this->registrar->suggest(
            [
                'monkeys',
                'kittens',
            ],
            [
                'com',
                'net',
                'org',
            ],
            5,
            'suggestion',
        );

        foreach ($result as $data) {
            $this->assertSame('suggestion', $data['type']);
            if ($data['available'] && $data['price'] !== null) {
                $this->assertIsFloat($data['price']);
                $this->assertGreaterThan(0, $data['price']);
            }
        }
    }

    public function testSuggestPremiumWithPriceFilter(): void
    {
        // Premium domains with price filters
        $result = $this->registrar->suggest(
            'computer',
            [
                'com',
                'net',
            ],
            5,
            'premium',
            10000,
            100,
        );

        $this->assertLessThanOrEqual(5, \count($result));

        foreach ($result as $data) {
            $this->assertSame('premium', $data['type']);
            if ($data['price'] !== null) {
                $this->assertIsFloat($data['price']);
                $this->assertGreaterThanOrEqual(100, $data['price']);
                $this->assertLessThanOrEqual(10000, $data['price']);
            }
        }
    }

    public function testTransferNotRegistered(): void
    {
        $domain = $this->generateRandomString() . '.net';

        try {
            $result = $this->registrar->transfer($domain, 'test-auth-code');
            $this->assertNotEmpty($result);
        } catch (DomainNotTransferableException $e) {
            $this->assertSame(OpenSRS::RESPONSE_CODE_DOMAIN_NOT_TRANSFERABLE, $e->getCode());
            $this->assertSame('Domain is not transferable: Domain not registered', $e->getMessage());
        }
    }

    public function testTransferAlreadyExists(): void
    {
        try {
            $result = $this->registrar->transfer($this->testDomain, 'test-auth-code');
            $this->assertNotEmpty($result);
        } catch (DomainNotTransferableException $e) {
            $this->assertSame(OpenSRS::RESPONSE_CODE_DOMAIN_NOT_TRANSFERABLE, $e->getCode());
            $this->assertStringContainsString('Domain is not transferable: Domain already exists', $e->getMessage());
        }
    }
}
