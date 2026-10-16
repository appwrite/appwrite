<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Platform\Tasks\Install;
use PHPUnit\Framework\TestCase;

final class InstallTest extends TestCase
{
    protected ?Install $install = null;

    protected function setUp(): void
    {
        $this->install = new Install();
    }

    protected function tearDown(): void
    {
        $this->install = null;
    }

    private function makeVar(string $name, ?string $default, string $filter = '', bool $required = false): array
    {
        return [
            'name' => $name,
            'default' => $default,
            'required' => $required,
            'question' => '',
            'filter' => $filter,
        ];
    }

    private function baseVars(?string $dbHostDefault, ?string $dbPortDefault, string $dbAdapterDefault = 'postgresql'): array
    {
        return [
            '_APP_DB_ADAPTER' => $this->makeVar('_APP_DB_ADAPTER', $dbAdapterDefault, required: true),
            '_APP_DB_HOST' => $this->makeVar('_APP_DB_HOST', $dbHostDefault),
            '_APP_DB_PORT' => $this->makeVar('_APP_DB_PORT', $dbPortDefault),
        ];
    }

    public function testPreservesCustomDatabaseHostOnUpgrade(): void
    {
        // Simulates an upgrade where the user has _APP_DB_HOST pointing at an
        // external database (preserved into the vars default by the existing
        // installation detection logic in action()).
        $vars = $this->baseVars('my-remote-db.example.com', '5433');

        $input = $this->install->prepareEnvironmentVariables(
            ['_APP_DB_ADAPTER' => 'postgresql'],
            $vars,
            false
        );

        $this->assertSame('my-remote-db.example.com', $input['_APP_DB_HOST']);
        $this->assertSame('5433', $input['_APP_DB_PORT']);
    }

    public function testAppliesContainerHostForFreshInstall(): void
    {
        // Fresh install: no existing installation, so the default is still
        // the config's own known container hostname for the chosen adapter.
        $vars = $this->baseVars('postgresql', '5432');

        $input = $this->install->prepareEnvironmentVariables(
            ['_APP_DB_ADAPTER' => 'mongodb'],
            $vars,
            true
        );

        $this->assertSame('mongodb', $input['_APP_DB_HOST']);
        $this->assertSame(27017, $input['_APP_DB_PORT']);
    }

    public function testKeepsContainerHostInSyncWhenAlreadyUsingDefaultContainer(): void
    {
        // Upgrade where the previous install used the bundled mariadb
        // container; switching adapter should still follow the container.
        $vars = $this->baseVars('mariadb', '3306', 'mariadb');

        $input = $this->install->prepareEnvironmentVariables(
            ['_APP_DB_ADAPTER' => 'mariadb'],
            $vars,
            false
        );

        $this->assertSame('mariadb', $input['_APP_DB_HOST']);
        $this->assertSame(3306, $input['_APP_DB_PORT']);
    }
}
