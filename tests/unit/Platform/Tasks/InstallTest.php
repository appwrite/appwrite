<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Platform\Tasks\Install;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../app/init.php';

final class InstallTest extends TestCase
{
    private Install $install;

    protected function setUp(): void
    {
        // Ensure a deterministic, opted-in environment for each test.
        \putenv('DO_NOT_TRACK');

        $this->install = new Install();
    }

    protected function tearDown(): void
    {
        \putenv('DO_NOT_TRACK');
    }

    /**
     * @return array<string, mixed>
     */
    private function productionInput(): array
    {
        return [
            '_APP_ENV' => 'production',
            '_APP_DOMAIN' => 'example.com',
            '_APP_DB_ADAPTER' => 'mariadb',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function account(): array
    {
        return [
            'name' => 'Jane Admin',
            'email' => 'jane@example.com',
        ];
    }

    public function testPayloadIncludesAdminNameAndEmail(): void
    {
        $payload = $this->install->buildSelfHostedInstallPayload($this->productionInput(), false, '1.9.6', $this->account());

        $this->assertIsArray($payload);
        $this->assertSame('install', $payload['action']);
        $this->assertSame('self-hosted', $payload['account']);
        $this->assertSame('1.9.6', $payload['version']);

        $data = \json_decode($payload['data'], true);
        $this->assertIsArray($data);

        $this->assertSame('Jane Admin', $data['name']);
        $this->assertSame('jane@example.com', $data['email']);
        $this->assertSame('example.com', $data['domain']);
        $this->assertSame('mariadb', $data['database']);
        $this->assertArrayHasKey('os', $data);
        $this->assertArrayHasKey('arch', $data);
    }

    public function testUpgradeActionIsReported(): void
    {
        $payload = $this->install->buildSelfHostedInstallPayload($this->productionInput(), true, '1.9.6', $this->account());

        $this->assertIsArray($payload);
        $this->assertSame('upgrade', $payload['action']);
        $this->assertSame('self_hosted_upgrade', $payload['label']);
    }

    public function testDoNotTrackDisablesTelemetry(): void
    {
        \putenv('DO_NOT_TRACK=1');

        $this->assertTrue($this->install->isTelemetryDisabled());
        $this->assertNull($this->install->buildSelfHostedInstallPayload($this->productionInput(), false, '1.9.6', $this->account()));
    }

    public function testDoNotTrackTrueValueDisablesTelemetry(): void
    {
        \putenv('DO_NOT_TRACK=true');

        $this->assertTrue($this->install->isTelemetryDisabled());
    }

    public function testDoNotTrackYesValueDisablesTelemetry(): void
    {
        \putenv('DO_NOT_TRACK=yes');

        $this->assertTrue($this->install->isTelemetryDisabled());
        $this->assertNull($this->install->buildSelfHostedInstallPayload($this->productionInput(), false, '1.9.6', $this->account()));
    }

    public function testTelemetryEnabledByDefault(): void
    {
        $this->assertFalse($this->install->isTelemetryDisabled());
    }

    public function testNonProductionEnvironmentIsNotTracked(): void
    {
        $input = $this->productionInput();
        $input['_APP_ENV'] = 'development';

        $this->assertNull($this->install->buildSelfHostedInstallPayload($input, false, '1.9.6', $this->account()));
    }

    public function testLocalhostDomainIsNotTracked(): void
    {
        $input = $this->productionInput();
        $input['_APP_DOMAIN'] = 'localhost';

        $this->assertNull($this->install->buildSelfHostedInstallPayload($input, false, '1.9.6', $this->account()));
    }
}
