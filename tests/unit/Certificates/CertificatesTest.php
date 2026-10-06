<?php

declare(strict_types=1);

namespace Tests\Unit\Certificates;

use Appwrite\Certificates\Certificates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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

    public static function autoIssueProvider(): \Iterator
    {
        yield 'enabled on self-hosted' => ['self-hosted', 'enabled', 'example.com', 'Appwrite', true];
        yield 'disabled by operator' => ['self-hosted', 'disabled', 'example.com', 'Appwrite', false];
        yield 'disabled outside self-hosted' => ['cloud', 'enabled', 'example.com', 'Appwrite', false];
        yield 'disabled for local domain' => ['self-hosted', 'enabled', 'localhost', 'Appwrite', false];
        yield 'disabled for non-Appwrite owner' => ['self-hosted', 'enabled', 'example.com', '', false];
    }
}
