<?php

declare(strict_types=1);

namespace Tests\Unit\Certificates;

use Appwrite\Certificates\Certificates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Certificates\Status;
use Utopia\Database\Document;

final class CertificatesTest extends TestCase
{
    #[DataProvider('autoIssueProvider')]
    public function testIsAutoIssueEnabled(string $edition, string $option, string $hostname, string $owner, bool $expected): void
    {
        $certificates = new Certificates($edition, $option);

        $this->assertSame($expected, $certificates->isAutoIssueEnabled(new Document([
            'domain' => $hostname,
            'owner' => $owner,
        ])));
    }

    public function testIsAutoIssueEnabledDefaults(): void
    {
        $certificates = new Certificates();

        $this->assertTrue($certificates->isAutoIssueEnabled(new Document([
            'domain' => 'example.com',
            'owner' => 'Appwrite',
        ])));
    }

    #[DataProvider('statusProvider')]
    public function testProviderStatus(string $status, bool $issued, bool $inFlight): void
    {
        $certificates = new Certificates();

        $this->assertSame($issued, $certificates->isIssued($status));
        $this->assertSame($inFlight, $certificates->isInFlight($status));
    }

    public static function statusProvider(): \Iterator
    {
        // A renewing certificate is still live, so it counts as issued, not in flight.
        yield 'issued' => [Status::ISSUED, true, false];
        yield 'renewing' => [Status::RENEWING, true, false];
        yield 'pending' => [Status::PENDING, false, true];
        yield 'processing' => [Status::PROCESSING, false, true];
        // Neither: the provider holds nothing, so the worker issues.
        yield 'unknown' => [Status::UNKNOWN, false, false];
        yield 'failed' => [Status::FAILED, false, false];
    }

    public static function autoIssueProvider(): \Iterator
    {
        yield 'enabled on self-hosted' => ['self-hosted', 'enabled', 'example.com', 'Appwrite', true];
        yield 'disabled by operator' => ['self-hosted', 'disabled', 'example.com', 'Appwrite', false];
        yield 'disabled outside self-hosted' => ['cloud', 'enabled', 'example.com', 'Appwrite', false];
        yield 'disabled for local domain' => ['self-hosted', 'enabled', 'localhost', 'Appwrite', false];
        yield 'disabled for non-Appwrite owner' => ['self-hosted', 'enabled', 'example.com', '', false];
    }
}
