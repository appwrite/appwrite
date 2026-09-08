<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Execution\Store;
use Appwrite\Platform\Action;
use Appwrite\Usage\Connection;
use Utopia\Console;

class UsageSetup extends Action
{
    public static function getName(): string
    {
        return 'usage-setup';
    }

    public function __construct()
    {
        $this
            ->desc('Set up ClickHouse schemas')
            ->inject('usageConnection')
            ->inject('executionStore')
            ->callback($this->action(...));
    }

    public function action(Connection $usageConnection, Store $executionStore): void
    {
        if (!$usageConnection->isEnabled() && !$executionStore->isEnabled()) {
            Console::info('ClickHouse persistence is disabled; schema setup skipped');
            return;
        }

        // An operator may run this before ClickHouse finishes starting, so
        // retry on the same ladder the boot-time setup uses.
        $max = 15;
        $sleep = 2;
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                if ($usageConnection->isEnabled()) {
                    $usageConnection->setup();
                    $health = $usageConnection->healthCheck();
                    if (($health['healthy'] ?? false) !== true) {
                        throw new \RuntimeException('Usage schema health check failed');
                    }
                }

                if ($executionStore->isEnabled()) {
                    $executionStore->setup();
                    $health = $executionStore->healthCheck();
                    if (($health['schemaReady'] ?? false) !== true) {
                        throw new \RuntimeException('Execution schema health check failed');
                    }
                }

                Console::success('ClickHouse schemas are ready');
                return;
            } catch (\Throwable $th) {
                if ($attempt >= $max) {
                    throw $th;
                }
                Console::warning("ClickHouse schema setup attempt {$attempt} failed: " . $th->getMessage());
                sleep($sleep);
            }
        }
    }
}
