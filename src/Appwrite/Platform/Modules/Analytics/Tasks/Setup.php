<?php

namespace Appwrite\Platform\Modules\Analytics\Tasks;

use Appwrite\Platform\Modules\Analytics\Storage\ClickHouse;
use Utopia\Console;
use Utopia\Platform\Action;
use Utopia\System\System;

class Setup extends Action
{
    public static function getName(): string
    {
        return 'analytics-setup';
    }

    public function __construct()
    {
        $this
            ->desc('Bootstrap ClickHouse tables for the Analytics service')
            ->callback($this->action(...));
    }

    public function action(): void
    {
        // The setup task creates the schema (DDL only). Tenant data is written
        // per-request via the resource container, so no tenant is configured
        // here. Cloud and self-hosted deployments default to sharedTables=true;
        // single-tenant operators that prefer dedicated tables can adjust the
        // adapter wiring without changing this task.
        $adapter = new ClickHouse(
            host: System::getEnv('_APP_ANALYTICS_DB_HOST', 'clickhouse'),
            port: (int) System::getEnv('_APP_ANALYTICS_DB_PORT', 8123),
            user: System::getEnv('_APP_ANALYTICS_DB_USER', 'default'),
            pass: System::getEnv('_APP_ANALYTICS_DB_PASS', ''),
            database: System::getEnv('_APP_ANALYTICS_DB_NAME', 'appwrite'),
        );

        $adapter
            ->setNamespace('analytics')
            ->setSharedTables(true);

        Console::info('Setting up analytics ClickHouse schema...');
        $adapter->setup();
        Console::success('Analytics schema ready.');
    }
}
