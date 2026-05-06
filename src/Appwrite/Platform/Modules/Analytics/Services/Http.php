<?php

namespace Appwrite\Platform\Modules\Analytics\Services;

use Appwrite\Platform\Modules\Analytics\Http\Apps\Create as CreateApp;
use Appwrite\Platform\Modules\Analytics\Http\Apps\Get as GetApp;
use Appwrite\Platform\Modules\Analytics\Http\Events\Create as CreateEvent;
use Appwrite\Platform\Modules\Analytics\Http\Script\Get as GetScript;
use Appwrite\Platform\Modules\Analytics\Http\Stats\Get as GetStats;
use Appwrite\Platform\Modules\Analytics\Storage\ClickHouse;
use Throwable;
use Utopia\Console;
use Utopia\Platform\Service;
use Utopia\System\System;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Apps
        $this->addAction(CreateApp::getName(), new CreateApp());
        $this->addAction(GetApp::getName(), new GetApp());

        // Event ingestion
        $this->addAction(CreateEvent::getName(), new CreateEvent());

        // Stats
        $this->addAction(GetStats::getName(), new GetStats());

        // Tracking script
        $this->addAction(GetScript::getName(), new GetScript());
    }

    /**
     * Bootstrap the Analytics ClickHouse schema (database + tables) at HTTP
     * service initialization. Idempotent (uses CREATE ... IF NOT EXISTS), so
     * it is safe to call on every container boot.
     *
     * Skipped in production: the Cloud control plane handles schema migrations
     * via dedicated deployment tooling. Any failure (e.g., ClickHouse not yet
     * reachable) is logged as a warning rather than thrown, since analytics
     * endpoints will surface the error loudly when invoked.
     */
    public static function bootstrap(): void
    {
        if (System::getEnv('_APP_ENV', 'development') === 'production') {
            return;
        }

        try {
            $adapter = new ClickHouse(
                host: System::getEnv('_APP_ANALYTICS_DB_HOST', 'clickhouse'),
                port: (int) System::getEnv('_APP_ANALYTICS_DB_PORT', 8123),
                user: System::getEnv('_APP_ANALYTICS_DB_USER', 'default'),
                pass: System::getEnv('_APP_ANALYTICS_DB_PASS', ''),
                database: System::getEnv('_APP_ANALYTICS_DB_NAME', 'appwrite'),
            );

            $adapter
                ->setNamespace('analytics')
                ->setSharedTables(true)
                ->setup();

            Console::success('Analytics schema ready.');
        } catch (Throwable $th) {
            Console::warning('Analytics schema bootstrap skipped: ' . $th->getMessage());
        }
    }
}
