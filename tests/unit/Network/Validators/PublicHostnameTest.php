<?php

declare(strict_types=1);

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\HostnameNotResolved;
use Appwrite\Network\Validator\PublicHostname;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Network\FixedLookup;
use Utopia\Client\Destinations\IPRange;
use Utopia\Client\Destinations\PublicInternet;

final class PublicHostnameTest extends TestCase
{
    public function testAcceptsAHostThatResolvesOnlyToPublicAddresses(): void
    {
        $this->assertTrue($this->validator()->isValid('example.com'));
        $this->assertTrue($this->validator()->isValid('1.1.1.1'));
    }

    public function testRejectsWhatIsNotAHostname(): void
    {
        $validator = $this->validator();

        foreach (['', null, 123, [], '999.999.999.999'] as $value) {
            $this->assertFalse($validator->isValid($value), \var_export($value, true));
        }
    }

    public function testReasonNamesTheRefusedAddress(): void
    {
        $validator = $this->validator();

        $this->assertFalse($validator->isValid('rebind.example.com'));
        $this->assertStringContainsString('10.0.0.1', $validator->getDescription());
    }

    public function testReasonResetsBetweenCalls(): void
    {
        $validator = $this->validator();

        $validator->isValid('');
        $this->assertSame('Hostname is empty.', $validator->getDescription());

        $validator->isValid('127.0.0.1');
        $this->assertStringContainsString('127.0.0.1', $validator->getDescription());

        $this->assertTrue($validator->isValid('example.com'));
        $this->assertSame('Value must be a publicly routable hostname or address.', $validator->getDescription());
    }

    public function testAddressIsOneTheHostResolvesTo(): void
    {
        $hostname = $this->validator();

        $this->assertSame('93.184.215.14', $hostname->address('example.com'));
        $this->assertSame('93.184.215.14', $hostname->address(' Example.COM '));
        $this->assertSame('2606:4700:4700::1111', $hostname->address('[2606:4700:4700::1111]'));
    }

    public function testAHostWithAnyRefusedAddressIsRefused(): void
    {
        // The connection could land on either answer, so one private answer refuses the host
        $this->assertRefused($this->validator(), 'rebind.example.com', 'resolves to private or reserved address 10.0.0.1');
        $this->assertRefused($this->validator(), '169.254.169.254', 'Address 169.254.169.254 is in a private or reserved range');
        $this->assertRefused($this->validator(), 'nowhere.example.com', 'does not resolve');
        $this->assertRefused($this->validator(), ' ', 'Hostname is empty');
    }

    public function testAHostThatGaveNoAddressIsToldApartFromOneThatWasRefused(): void
    {
        $hostname = $this->validator();

        try {
            $hostname->address('nowhere.example.com');
            $this->fail('Expected a host with no address to be refused.');
        } catch (HostnameNotResolved $exception) {
            // Still a refusal for a check that only wants to refuse the host
            $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
        }

        foreach (['rebind.example.com', '169.254.169.254'] as $host) {
            try {
                $hostname->address($host);
                $this->fail("Expected {$host} to be refused.");
            } catch (HostnameNotResolved) {
                $this->fail("{$host} resolved to an address that is not allowed, which is not a lookup that failed.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testResolverAnswersWithTheAddressAnAdapterMayDial(): void
    {
        $this->assertSame('93.184.215.14', $this->validator()->resolver()('example.com'));
    }

    public function testResolverRefusesWhatMayNeverBeDialledForGood(): void
    {
        $resolve = $this->validator()->resolver();

        foreach (['rebind.example.com', '169.254.169.254', ' '] as $host) {
            $caught = null;

            try {
                $resolve($host);
            } catch (\Throwable $exception) {
                $caught = $exception;
            }

            // A refusal for good, not the RuntimeException of an answer that may differ next time
            $this->assertInstanceOf(\InvalidArgumentException::class, $caught, $host);
        }
    }

    public function testResolverDoesNotCallAFailedLookupAFinalRefusal(): void
    {
        $caught = null;

        try {
            $this->validator()->resolver()('nowhere.example.com');
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        // A RuntimeException, so not the InvalidArgumentException of a refusal for good
        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertStringContainsString('does not resolve', $caught->getMessage());
        $this->assertInstanceOf(HostnameNotResolved::class, $caught->getPrevious());
    }

    public function testAllowedInternalRangesAdmitTheirAddressesOnly(): void
    {
        $hostname = new PublicHostname(new PublicInternet(new IPRange('10.0.0.0/8')), new FixedLookup([
            'internal.example.com' => ['10.1.2.3'],
        ]));

        $this->assertSame('10.1.2.3', $hostname->address('internal.example.com'));
        $this->assertRefused($hostname, '127.0.0.1', 'private or reserved');
    }

    private function assertRefused(PublicHostname $hostname, string $host, string $reason): void
    {
        try {
            $address = $hostname->address($host);
            $this->fail("Expected {$host} to be refused, got {$address}.");
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
        }
    }

    private function validator(): PublicHostname
    {
        return new PublicHostname(new PublicInternet(), new FixedLookup([
            'example.com' => ['93.184.215.14'],
            'rebind.example.com' => ['93.184.215.14', '10.0.0.1'],
        ]));
    }
}
