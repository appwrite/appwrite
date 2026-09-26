<?php

declare(strict_types=1);

namespace Tests\Unit\Installer;

use Appwrite\Installer\Report;
use Appwrite\Installer\Reporter;
use PHPUnit\Framework\TestCase;

final class ReporterTest extends TestCase
{
    public function testInstallIsPostedToInstallations(): void
    {
        $recorder = new Recorder();

        (new Reporter($recorder))->send($this->report());

        $this->assertCount(1, $recorder->requests);
        $request = $recorder->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://cloud.appwrite.io/v1/growth/installations', $request['url']);
        $this->assertSame('console', $request['headers']['x-appwrite-project']);
        $this->assertSame('application/json', $request['headers']['content-type']);
        $this->assertArrayNotHasKey('authorization', $request['headers']);
    }

    public function testBodyIsFlatInstallationsParams(): void
    {
        $recorder = new Recorder();

        (new Reporter($recorder))->send($this->report());

        $request = $recorder->requests[0];
        $this->assertIsString($request['body']);
        $body = \json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Ada', $body['name']);
        $this->assertSame('ada@example.com', $body['email']);
        $this->assertSame('appwrite.example.com', $body['domain']);
        $this->assertSame('postgresql', $body['database']);
        $this->assertSame('203.0.113.7', $body['hostIp']);
        $this->assertSame(4, $body['cpus']);
        $this->assertSame(7954, $body['ram']);
        $this->assertSame($request['options']->getUserAgent(), $body['userAgent']);
        $this->assertStringContainsString($body['version'], $body['userAgent']);
        $this->assertArrayNotHasKey('data', $body);
        $this->assertArrayNotHasKey('action', $body);
    }

    public function testUnknownValuesAreLeftOutOfBody(): void
    {
        $recorder = new Recorder();

        (new Reporter($recorder))->send($this->report(name: null, email: '', ip: null, cpus: null, ram: null));

        $body = \json_decode($recorder->requests[0]['body'], true, flags: JSON_THROW_ON_ERROR);

        foreach (['name', 'email', 'hostIp', 'cpus', 'ram'] as $key) {
            $this->assertArrayNotHasKey($key, $body);
        }
        $this->assertSame('appwrite.example.com', $body['domain']);
    }

    public function testUpgradeIsNotSent(): void
    {
        $recorder = new Recorder();

        (new Reporter($recorder))->send($this->report(action: Report::ACTION_UPGRADE));

        $this->assertSame([], $recorder->requests);
    }

    public function testIncompleteInstallIsNotSent(): void
    {
        $recorder = new Recorder();
        $reporter = new Reporter($recorder);

        $reporter->send($this->report(version: ''));
        $reporter->send($this->report(domain: ''));
        $reporter->send($this->report(database: ''));

        $this->assertSame([], $recorder->requests);
    }

    public function testFailureDoesNotReachInstaller(): void
    {
        $recorder = new Recorder(new \RuntimeException('connection refused'));

        (new Reporter($recorder))->send($this->report());

        $this->assertCount(1, $recorder->requests);
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
