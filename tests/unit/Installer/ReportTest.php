<?php

declare(strict_types=1);

namespace Tests\Unit\Installer;

use Appwrite\Installer\Report;
use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase
{
    public function testPayloadMapsToInstallationsParams(): void
    {
        $report = $this->report();

        $this->assertSame([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'version' => '1.9.0',
            'domain' => 'appwrite.example.com',
            'database' => 'postgresql',
            'hostIp' => '203.0.113.7',
            'userAgent' => 'Appwrite-Installer/1.9.0 (cli-headless; stable; combined; started)',
            'os' => 'Linux 6.8.0',
            'arch' => 'x86_64',
            'cpus' => 4,
            'ram' => 7954,
        ], $report->payload());
    }

    public function testPayloadOmitsUnknownValues(): void
    {
        $report = $this->report(name: null, email: '', ip: null, cpus: null, ram: null);

        $payload = $report->payload();

        $this->assertArrayNotHasKey('name', $payload);
        $this->assertArrayNotHasKey('email', $payload);
        $this->assertArrayNotHasKey('hostIp', $payload);
        $this->assertArrayNotHasKey('cpus', $payload);
        $this->assertArrayNotHasKey('ram', $payload);
        $this->assertSame('appwrite.example.com', $payload['domain']);
    }

    public function testInstallIsSendable(): void
    {
        $this->assertTrue($this->report()->sendable());
    }

    public function testUpgradeIsNotSendable(): void
    {
        $this->assertFalse($this->report(action: Report::ACTION_UPGRADE)->sendable());
    }

    public function testIncompleteReportIsNotSendable(): void
    {
        $this->assertFalse($this->report(version: '')->sendable());
        $this->assertFalse($this->report(domain: '')->sendable());
        $this->assertFalse($this->report(database: '')->sendable());
    }

    public function testOptedOut(): void
    {
        $this->assertFalse(Report::optedOut('', Report::ENVIRONMENT_PRODUCTION, false));
        $this->assertTrue(Report::optedOut('1', Report::ENVIRONMENT_PRODUCTION, false));
        $this->assertTrue(Report::optedOut('TRUE', Report::ENVIRONMENT_PRODUCTION, false));
        $this->assertTrue(Report::optedOut('', 'development', false));
        $this->assertTrue(Report::optedOut('', Report::ENVIRONMENT_PRODUCTION, true));
    }

    private function report(
        string $action = Report::ACTION_INSTALL,
        string $version = '1.9.0',
        string $domain = 'appwrite.example.com',
        string $database = 'postgresql',
        ?string $name = 'Ada',
        ?string $email = 'ada@example.com',
        ?string $ip = '203.0.113.7',
        ?int $cpus = 4,
        ?int $ram = 7954,
    ): Report {
        return new Report(
            action: $action,
            source: Report::SOURCE_CLI_HEADLESS,
            version: $version,
            channel: 'stable',
            topology: 'combined',
            domain: $domain,
            database: $database,
            started: true,
            name: $name,
            email: $email,
            ip: $ip,
            os: 'Linux 6.8.0',
            arch: 'x86_64',
            cpus: $cpus,
            ram: $ram,
        );
    }
}
