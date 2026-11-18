<?php

declare(strict_types=1);

namespace Tests\Unit\SMTP\Validator;

use Appwrite\Network\Validator\PublicHostname;
use Appwrite\SMTP\Validator\Host;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Network\FixedLookup;
use Utopia\Client\Destinations\PublicInternet;

final class HostTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string}>
     */
    public static function publicHosts(): \Iterator
    {
        yield 'name' => ['smtp.example.com'];
        yield 'name and port' => ['smtp.example.com:587'];
        yield 'encryption prefix' => ['tls://smtp.example.com:587'];
        yield 'upper case prefix' => ['SSL://smtp.example.com:465'];
        yield 'list' => ['tls://smtp.example.com:587;ssl://backup.example.com:465;smtp.example.com'];
        yield 'empty entries' => ['smtp.example.com;;'];
        yield 'address' => ['93.184.215.14:25'];
        yield 'bracketed address' => ['[2606:4700:4700::1111]:587'];
        yield 'bare address' => ['2606:4700:4700::1111'];
    }

    #[DataProvider('publicHosts')]
    public function testAcceptsPublicServers(string $value): void
    {
        $this->assertTrue($this->validator()->isValid($value));
    }

    /**
     * @return \Iterator<string, array{mixed, string}>
     */
    public static function refusedHosts(): \Iterator
    {
        yield 'loopback' => ['127.0.0.1', 'private or reserved'];
        yield 'loopback with port' => ['127.0.0.1:25', 'private or reserved'];
        yield 'metadata address' => ['ssl://169.254.169.254:465', 'private or reserved'];
        yield 'bracketed loopback' => ['[::1]:25', 'private or reserved'];
        yield 'bare loopback' => ['::1', 'private or reserved'];
        yield 'name resolving privately' => ['internal.example.com', '10.0.0.1'];
        yield 'private server after a public one' => ['smtp.example.com;internal.example.com:25', '10.0.0.1'];
        yield 'unresolved' => ['nowhere.example.com', 'does not resolve'];
        yield 'empty' => ['', 'empty'];
        yield 'only separators' => [';;', 'empty'];
        yield 'not a string' => [123, 'empty'];
    }

    #[DataProvider('refusedHosts')]
    public function testRefusesPrivateOrMissingServers(mixed $value, string $reason): void
    {
        $validator = $this->validator();

        $this->assertFalse($validator->isValid($value));
        $this->assertStringContainsString($reason, $validator->getDescription());
    }

    public function testAHostStoredForLaterMayNotResolveYet(): void
    {
        $validator = $this->validator(resolved: false);

        $this->assertTrue($validator->isValid('nowhere.example.com'));
        $this->assertFalse($validator->isValid('internal.example.com'));
        $this->assertFalse($validator->isValid('127.0.0.1'));
    }

    public function testAddressesNameEachServerAsTheAdapterDoes(): void
    {
        $this->assertSame(
            [
                'smtp.example.com' => '93.184.215.14',
                '[2606:4700:4700::1111]' => '[2606:4700:4700::1111]',
                'ipv6.example.com' => '[2606:4700:4700::1111]',
            ],
            $this->validator()->addresses('tls://smtp.example.com:587;[2606:4700:4700::1111]:25;ipv6.example.com'),
        );
    }

    public function testAddressesRefuseAPrivateServer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('10.0.0.1');

        $this->validator()->addresses('smtp.example.com;internal.example.com');
    }

    private function validator(bool $resolved = true): Host
    {
        return new Host(new PublicHostname(new PublicInternet(), new FixedLookup([
            'smtp.example.com' => ['93.184.215.14'],
            'backup.example.com' => ['93.184.215.15'],
            'internal.example.com' => ['10.0.0.1'],
            'ipv6.example.com' => ['2606:4700:4700::1111'],
        ])), $resolved);
    }
}
