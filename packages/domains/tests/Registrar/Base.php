<?php

declare(strict_types=1);

namespace Utopia\Tests\Registrar;

use PHPUnit\Framework\TestCase;
use Utopia\Domains\Registrar;
use Utopia\Domains\Registrar\Contact;
use Utopia\Domains\Registrar\Exception\DomainNotTransferableException;
use Utopia\Domains\Registrar\Exception\DomainTakenException;
use Utopia\Domains\Registrar\Exception\InvalidAuthCodeException;
use Utopia\Domains\Registrar\Exception\InvalidContactException;
use Utopia\Domains\Registrar\Exception\PriceNotFoundException;
use Utopia\Domains\Registrar\TransferStatusEnum;
use Utopia\Domains\Registrar\UpdateDetails;

abstract class Base extends TestCase
{
    /**
     * Get the registrar instance to test
     */
    abstract protected function getRegistrar(): Registrar;

    /**
     * Get the registrar instance with cache enabled
     */
    abstract protected function getRegistrarWithCache(): Registrar;

    /**
     * Get a test domain that exists and is owned by the test account
     * Used for tests that require an existing domain
     */
    abstract protected function getTestDomain(): string;

    /**
     * Get the expected adapter name
     */
    abstract protected function getExpectedAdapterName(): string;

    /**
     * Get an UpdateDetails instance for testing
     *
     * @param bool|null $autoRenew Enable or disable automatic renewal
     */
    abstract protected function getUpdateDetails(?bool $autoRenew = null): UpdateDetails;

    /**
     * Get purchase contact info
     */
    protected function getPurchaseContact(string $suffix = ''): array
    {
        $contact = new Contact(
            'Test' . $suffix,
            'Tester' . $suffix,
            '+18031234567',
            'testing' . $suffix . '@test.com',
            '123 Main St' . $suffix,
            'Suite 100' . $suffix,
            '',
            'San Francisco' . $suffix,
            'CA',
            'US',
            '94105',
            'Test Inc' . $suffix,
        );

        return [
            'owner' => $contact,
            'admin' => $contact,
            'tech' => $contact,
            'billing' => $contact,
        ];
    }

    /**
     * Generate a random string for domain names
     */
    protected function generateRandomString(int $length = 10): string
    {
        $characters = 'abcdefghijklmnopqrstuvwxyz';
        $charactersLength = \strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }

        return $randomString;
    }

    /**
     * Get default TLD for testing
     */
    protected function getDefaultTld(): string
    {
        return 'com';
    }

    /**
     * Get a domain to use for pricing tests
     * Can be overridden by adapters if they have restrictions
     */
    protected function getPricingTestDomain(): string
    {
        return 'example.' . $this->getDefaultTld();
    }

    /**
     * Get an available premium domain, or null when the adapter has none to test with
     */
    protected function getPremiumTestDomain(): ?string
    {
        return null;
    }

    public function testGetName(): void
    {
        $name = $this->getRegistrar()->getName();
        $this->assertSame($this->getExpectedAdapterName(), $name);
    }

    public function testAvailable(): void
    {
        $availableDomain = $this->generateRandomString() . '.' . $this->getDefaultTld();
        $result = $this->getRegistrar()->available([$availableDomain, 'google.com']);

        $this->assertSame([
            $availableDomain => true,
            'google.com' => false,
        ], $result);
    }

    public function testPurchase(): void
    {
        $domain = $this->generateRandomString() . '.' . $this->getDefaultTld();
        $result = $this->getRegistrar()->purchase($domain, $this->getPurchaseContact(), 1);

        $this->assertNotEmpty($result);
    }

    public function testPurchaseTakenDomain(): void
    {
        $domain = 'google.com';

        $this->expectException(DomainTakenException::class);
        $this->getRegistrar()->purchase($domain, $this->getPurchaseContact(), 1);
    }

    public function testPurchaseWithInvalidContact(): void
    {
        $domain = $this->generateRandomString() . '.' . $this->getDefaultTld();

        $this->expectException(InvalidContactException::class);
        $this->getRegistrar()->purchase($domain, [
            new Contact(
                'John',
                'Doe',
                '+1234567890',
                'invalid-email',
                '123 Main St',
                'Suite 100',
                '',
                'San Francisco',
                'CA',
                'InvalidCountry',
                '94105',
                'Test Inc',
            ),
        ]);
    }

    public function testDomainInfo(): void
    {
        $testDomain = $this->getTestDomain();
        $result = $this->getRegistrar()->getDomain($testDomain);

        $this->assertSame($testDomain, $result->domain);
        $this->assertInstanceOf(\DateTime::class, $result->createdAt);
        $this->assertInstanceOf(\DateTime::class, $result->expiresAt);
        $this->assertIsBool($result->autoRenew);
        $this->assertIsArray($result->nameservers);
    }

    public function testCancelPurchase(): void
    {
        $result = $this->getRegistrar()->cancelPurchase();
        $this->assertTrue($result);
    }

    public function testTlds(): void
    {
        $tlds = $this->getRegistrar()->tlds();
        $this->assertNotEmpty($tlds);
    }

    public function testSuggest(): void
    {
        $result = $this->getRegistrar()->suggest(
            'example',
            ['com', 'net', 'org'],
            5,
        );

        $this->assertLessThanOrEqual(5, \count($result));

        foreach ($result as $domain => $data) {
            $this->assertIsString($domain);
            $this->assertArrayHasKey('available', $data);
            $this->assertArrayHasKey('price', $data);
            $this->assertArrayHasKey('type', $data);
            $this->assertIsBool($data['available']);

            if ($data['price'] !== null) {
                $this->assertIsFloat($data['price']);
            }
        }
    }

    public function testGetPrice(): void
    {
        $domain = $this->getPricingTestDomain();
        $result = $this->getRegistrar()->getPrice($domain, 1, Registrar::REG_TYPE_NEW);

        $this->assertGreaterThan(0, $result->price);
    }

    public function testGetPriceWithInvalidDomain(): void
    {
        $this->expectException(PriceNotFoundException::class);
        $this->getRegistrar()->getPrice('invalid.invalidtld', 1, Registrar::REG_TYPE_NEW);
    }

    public function testGetPriceWithCache(): void
    {
        $domain = $this->getPricingTestDomain();
        $registrar = $this->getRegistrarWithCache();

        $result1 = $registrar->getPrice($domain, 1, Registrar::REG_TYPE_NEW, 3600);
        $result2 = $registrar->getPrice($domain, 1, Registrar::REG_TYPE_NEW, 3600);
        $this->assertEquals($result1, $result2);
    }

    public function testGetPriceWithCustomTtl(): void
    {
        $domain = $this->getPricingTestDomain();
        $result = $this->getRegistrarWithCache()->getPrice($domain, 1, Registrar::REG_TYPE_NEW, 7200);

        $this->assertGreaterThan(0, $result->price);
    }

    public function testGetPriceAfterAvailable(): void
    {
        $available = $this->generateRandomString() . '.' . $this->getDefaultTld();
        $taken = 'google.com';
        $registrar = $this->getRegistrarWithCache();

        $availability = $registrar->available([$available, $taken]);
        $this->assertTrue($availability[$available]);
        $this->assertFalse($availability[$taken]);

        // Whatever an adapter remembers from the availability lookup, prices
        // after it must match a direct lookup for every domain, type and period.
        foreach ([$available, $taken] as $domain) {
            foreach ([Registrar::REG_TYPE_NEW, Registrar::REG_TYPE_RENEWAL, Registrar::REG_TYPE_TRANSFER] as $type) {
                $cached = $registrar->getPrice($domain, 1, $type);
                $direct = $this->getRegistrar()->getPrice($domain, 1, $type);

                $this->assertSame($direct->price, $cached->price, "{$type} price for {$domain}");
                $this->assertSame($direct->premium, $cached->premium, "premium flag for {$domain}");
            }
        }

        $multiYear = $registrar->getPrice($available, 3, Registrar::REG_TYPE_NEW);
        $this->assertSame($this->getRegistrar()->getPrice($available, 3, Registrar::REG_TYPE_NEW)->price, $multiYear->price);
    }

    public function testGetPremiumPriceAfterAvailable(): void
    {
        $domain = $this->getPremiumTestDomain();
        if ($domain === null) {
            $this->markTestSkipped('No premium test domain for this adapter');
        }

        $registrar = $this->getRegistrarWithCache();
        $this->assertTrue($registrar->available([$domain])[$domain]);

        $price = $registrar->getPrice($domain, 1, Registrar::REG_TYPE_NEW);
        $direct = $this->getRegistrar()->getPrice($domain, 1, Registrar::REG_TYPE_NEW);

        $this->assertTrue($price->premium);
        $this->assertSame($direct->price, $price->price);
    }

    public function testUpdateNameservers(): void
    {
        $testDomain = $this->getTestDomain();
        $nameservers = $this->getDefaultNameservers();

        $result = $this->getRegistrar()->updateNameservers($testDomain, $nameservers);

        $this->assertTrue($result['successful']);
        $this->assertArrayHasKey('nameservers', $result);
    }

    public function testUpdateDomain(): void
    {
        $testDomain = $this->getTestDomain();
        $originalAutoRenew = $this->getRegistrar()->getDomain($testDomain)->autoRenew;
        $updated = false;

        try {
            $result = $this->getRegistrar()->updateDomain(
                $testDomain,
                $this->getUpdateDetails(!$originalAutoRenew),
            );

            $this->assertTrue($result);
            $updated = true;
        } finally {
            if ($updated) {
                $this->getRegistrar()->updateDomain(
                    $testDomain,
                    $this->getUpdateDetails($originalAutoRenew),
                );
            }
        }
    }

    public function testRenewDomain(): void
    {
        $testDomain = $this->getTestDomain();

        try {
            $result = $this->getRegistrar()->renew($testDomain, 1);
            $this->assertIsString($result->orderId);
            $this->assertNotEmpty($result->orderId);
            $this->assertInstanceOf(\DateTime::class, $result->expiresAt);
            $this->assertInstanceOf(\DateTime::class, $result->expiresAt);
        } catch (\Exception $e) {
            // Renewal may fail for various reasons depending on the registrar
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function testTransfer(): void
    {
        $domain = $this->generateRandomString() . '.' . $this->getDefaultTld();

        try {
            $result = $this->getRegistrar()->transfer($domain, 'test-auth-code');

            $this->assertNotEmpty($result);
        } catch (\Exception $e) {
            $this->assertTrue(
                $e instanceof InvalidAuthCodeException || $e instanceof DomainNotTransferableException,
                'Expected InvalidAuthCodeException or DomainNotTransferableException, got ' . $e::class . ': ' . $e->getMessage() . ' (code ' . $e->getCode() . ')',
            );
        }
    }

    public function testGetAuthCode(): void
    {
        $testDomain = $this->getTestDomain();

        try {
            $authCode = $this->getRegistrar()->getAuthCode($testDomain);
            $this->assertNotEmpty($authCode);
        } catch (\Exception $e) {
            // Some domains may not support auth codes
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function testCheckTransferStatus(): void
    {
        $testDomain = $this->getTestDomain();
        $result = $this->getRegistrar()->checkTransferStatus($testDomain);

        $this->assertInstanceOf(TransferStatusEnum::class, $result->status);

        if ($result->status !== TransferStatusEnum::Transferrable && $result->reason !== null) {
            $this->assertIsString($result->reason);
        }

        $this->assertContains($result->status, [
            TransferStatusEnum::Transferrable,
            TransferStatusEnum::NotTransferrable,
            TransferStatusEnum::PendingOwner,
            TransferStatusEnum::PendingAdmin,
            TransferStatusEnum::PendingRegistry,
            TransferStatusEnum::Completed,
            TransferStatusEnum::Cancelled,
            TransferStatusEnum::ServiceUnavailable,
        ]);
    }

    /**
     * Get default nameservers for testing
     * Can be overridden by child classes
     */
    protected function getDefaultNameservers(): array
    {
        return [
            'ns1.example.com',
            'ns2.example.com',
        ];
    }
}
