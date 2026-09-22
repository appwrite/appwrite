<?php

namespace Tests\Unit\Installer;

use Appwrite\Installer\Report;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReportTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool, bool}>
     */
    public static function optOutProvider(): array
    {
        return [
            'production reports' => ['', Report::ENVIRONMENT_PRODUCTION, false, false],
            'local checkout skips' => ['', Report::ENVIRONMENT_PRODUCTION, true, true],
            'development skips' => ['', 'development', false, true],
            'DO_NOT_TRACK=1 skips' => ['1', Report::ENVIRONMENT_PRODUCTION, false, true],
            'DO_NOT_TRACK=true skips' => ['TRUE', Report::ENVIRONMENT_PRODUCTION, false, true],
            'DO_NOT_TRACK=yes skips' => ['yes', Report::ENVIRONMENT_PRODUCTION, false, true],
            'DO_NOT_TRACK=0 reports' => ['0', Report::ENVIRONMENT_PRODUCTION, false, false],
        ];
    }

    #[DataProvider('optOutProvider')]
    public function testOptedOut(string $doNotTrack, string $environment, bool $local, bool $expected): void
    {
        $this->assertSame($expected, Report::optedOut($doNotTrack, $environment, $local));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function loopbackProvider(): array
    {
        return [
            'localhost' => ['localhost', true],
            'loopback ip' => ['127.0.0.1', true],
            'unspecified ip' => ['0.0.0.0', true],
            'private ip' => ['192.168.1.3', false],
            'hostname' => ['appwrite.example.com', false],
        ];
    }

    #[DataProvider('loopbackProvider')]
    public function testIsLoopback(string $domain, bool $expected): void
    {
        $this->assertSame($expected, Report::isLoopback($domain));
    }

    public function testLoopbackDomainIsNotOptedOut(): void
    {
        // Test for SUCCESS: a localhost install used to be dropped before it reached
        // the growth API, which hid every headless CLI install. Only the opt-out
        // signals decide now; the domain is reported as-is.
        $this->assertFalse(Report::optedOut('', Report::ENVIRONMENT_PRODUCTION, false));

        $report = $this->report(domain: 'localhost');
        $data = \json_decode($report->payload()['data'], true);

        $this->assertSame('localhost', $data['domain']);
        $this->assertSame('https://localhost', $report->payload()['url']);
    }

    public function testPayload(): void
    {
        $report = $this->report();
        $payload = $report->payload();

        $this->assertSame(Report::ACTION_INSTALL, $payload['action']);
        $this->assertSame(Report::ACCOUNT, $payload['account']);
        $this->assertSame('https://appwrite.example.com', $payload['url']);
        $this->assertSame(Report::CATEGORY, $payload['category']);
        $this->assertSame('self_hosted_install', $payload['label']);
        $this->assertSame('2.0.0', $payload['version']);

        $data = \json_decode($payload['data'], true);

        $this->assertSame('Admin', $data['name']);
        $this->assertSame('admin@example.com', $data['email']);
        $this->assertSame('appwrite.example.com', $data['domain']);
        $this->assertSame('postgresql', $data['database']);
        $this->assertArrayNotHasKey('source', $data);
        $this->assertArrayNotHasKey('started', $data);
        $this->assertSame('203.0.113.7', $data['ip']);
        $this->assertSame('Linux 6.1', $data['os']);
        $this->assertSame('x86_64', $data['arch']);
        $this->assertSame(4, $data['cpus']);
        $this->assertSame(8192, $data['ram']);
    }

    public function testUserAgent(): void
    {
        // Growth stores the User-Agent on every installation row, so the facts a
        // dashboard splits on travel there instead of as new columns.
        $this->assertSame(
            'Appwrite-Installer/2.0.0 (web; stable; combined; started)',
            $this->report()->userAgent()
        );
        $this->assertSame(
            'Appwrite-Installer/2.0.0 (cli-headless; stable; combined; not-started)',
            $this->report(source: Report::SOURCE_CLI_HEADLESS, started: false)->userAgent()
        );
    }

    public function testUpgradeLabel(): void
    {
        $payload = $this->report(action: Report::ACTION_UPGRADE)->payload();

        $this->assertSame(Report::ACTION_UPGRADE, $payload['action']);
        $this->assertSame('self_hosted_upgrade', $payload['label']);
    }

    public function testHeadlessInstallWithoutAccountOrStart(): void
    {
        // A headless CLI install collects no account and, with --no-start, never
        // boots the containers. Both facts are reported rather than faked.
        $report = $this->report(
            source: Report::SOURCE_CLI_HEADLESS,
            started: false,
            name: null,
            email: null,
            ip: null,
            cpus: null,
            ram: null,
        );
        $data = \json_decode($report->payload()['data'], true);

        $this->assertNull($data['name']);
        $this->assertNull($data['email']);
        $this->assertNull($data['ip']);
        $this->assertNull($data['cpus']);
        $this->assertNull($data['ram']);
    }

    private function report(
        string $action = Report::ACTION_INSTALL,
        string $source = Report::SOURCE_WEB,
        string $domain = 'appwrite.example.com',
        bool $started = true,
        ?string $name = 'Admin',
        ?string $email = 'admin@example.com',
        ?string $ip = '203.0.113.7',
        ?int $cpus = 4,
        ?int $ram = 8192,
    ): Report {
        return new Report(
            action: $action,
            source: $source,
            version: '2.0.0',
            channel: 'stable',
            topology: 'combined',
            domain: $domain,
            database: 'postgresql',
            started: $started,
            name: $name,
            email: $email,
            ip: $ip,
            os: 'Linux 6.1',
            arch: 'x86_64',
            cpus: $cpus,
            ram: $ram,
        );
    }
}
