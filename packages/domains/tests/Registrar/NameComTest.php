<?php

declare(strict_types=1);

namespace Utopia\Tests\Registrar;

use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache as UtopiaCache;
use Utopia\Domains\Cache;
use Utopia\Domains\Registrar;
use Utopia\Domains\Registrar\Adapter\NameCom;
use Utopia\Domains\Registrar\Exception\AuthException;
use Utopia\Domains\Registrar\Exception\InvalidPeriodException;
use Utopia\Domains\Registrar\Exception\UnsupportedTldException;
use Utopia\Domains\Registrar\UpdateDetails;

final class NameComTest extends Base
{
    private Registrar $registrar;
    private Registrar $registrarWithCache;

    protected function setUp(): void
    {
        $username = getenv('NAMECOM_USERNAME');
        $token = getenv('NAMECOM_TOKEN');
        if ($username === false || $username === '' || $token === false || $token === '') {
            $this->markTestSkipped('NAMECOM_USERNAME and NAMECOM_TOKEN are required');
        }

        $endpoint = 'https://api.dev.name.com';

        $this->registrar = new Registrar(
            new NameCom($username, $token, $endpoint),
            [
                'ns1.name.com',
                'ns2.name.com',
            ],
        );

        $this->registrarWithCache = new Registrar(
            new NameCom($username, $token, $endpoint),
            [
                'ns1.name.com',
                'ns2.name.com',
            ],
            new Cache(new UtopiaCache(new Memory())),
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
        // For tests that need an existing domain, we'll purchase one on the fly
        // or return a domain we know exists
        $testDomain = $this->generateRandomString() . '.com';
        $this->registrar->purchase($testDomain, $this->getPurchaseContact(), 1);
        return $testDomain;
    }

    protected function getExpectedAdapterName(): string
    {
        return 'namecom';
    }

    #[\Override]
    protected function getDefaultNameservers(): array
    {
        return [
            'ns1.name.com',
            'ns2.name.com',
        ];
    }

    protected function getUpdateDetails(?bool $autoRenew = null): UpdateDetails
    {
        return new UpdateDetails($autoRenew);
    }

    #[\Override]
    protected function getPricingTestDomain(): string
    {
        // Name.com doesn't like 'example.com' for pricing
        return 'example-test-domain.com';
    }

    #[\Override]
    protected function getPremiumTestDomain(): string
    {
        return 'shop.dev';
    }

    // NameCom-specific tests

    public function testAvailableInMultipleBatches(): void
    {
        $prefix = $this->generateRandomString();
        $domains = array_map(
            fn(int $index): string => "{$prefix}-{$index}.com",
            range(1, 51),
        );

        $result = $this->registrar->available($domains);

        $this->assertCount(51, $result);
        foreach ($domains as $domain) {
            $this->assertTrue($result[$domain]);
        }
    }

    public function testPurchaseWithInvalidCredentials(): void
    {
        $adapter = new NameCom(
            'invalid-username',
            'invalid-token',
            'https://api.dev.name.com',
        );

        $registrar = new Registrar(
            $adapter,
            [
                'ns1.name.com',
                'ns2.name.com',
            ],
        );

        $domain = $this->generateRandomString() . '.com';

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Failed to purchase domain: Unauthorized');

        $registrar->purchase($domain, $this->getPurchaseContact(), 1);
    }

    public function testSuggestPremiumDomains(): void
    {
        $result = $this->registrar->suggest(
            'business',
            ['com'],
            5,
            'premium',
            10000,
            100,
        );

        foreach ($result as $data) {
            $this->assertEquals('premium', $data['type']);
            if ($data['price'] !== null) {
                $this->assertGreaterThanOrEqual(100, $data['price']);
                $this->assertLessThanOrEqual(10000, $data['price']);
            }
        }
    }

    public function testSuggestWithFilter(): void
    {
        $result = $this->registrar->suggest(
            'testdomain',
            ['com'],
            5,
            'suggestion',
        );

        foreach ($result as $data) {
            $this->assertEquals('suggestion', $data['type']);
        }
    }

    public function testTransferUnsupportedTldDotIn(): void
    {
        $domain = $this->generateRandomString() . '.in';

        $this->expectException(UnsupportedTldException::class);
        $this->registrar->transfer($domain, 'test-auth-code');
    }

    public function testTransferUnsupportedTldDotXYZ(): void
    {
        $domain = $this->generateRandomString() . '.xyz';

        $this->expectException(UnsupportedTldException::class);
        $this->registrar->transfer($domain, 'test-auth-code');
    }

    #[\Override]
    public function testCheckTransferStatus(): void
    {
        $this->markTestSkipped('Name.com for some reason always returning 404 (Not Found) for transfer status check. Investigate later.');
    }

    public function testGetPriceWithInvalidPeriod(): void
    {
        $domain = $this->generateRandomString() . '.ai';

        $this->expectException(InvalidPeriodException::class);
        $this->expectExceptionMessage('Failed to get price for domain: ' . $domain . ' - Invalid value for years for this domain');

        $this->registrar->getPrice($domain, 1);
    }
}
