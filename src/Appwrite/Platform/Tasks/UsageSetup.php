<?php

namespace Appwrite\Platform\Tasks;

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
            ->desc('Set up the usage ClickHouse schema')
            ->inject('usageConnection')
            ->callback($this->action(...));
    }

    public function action(Connection $usageConnection): void
    {
        if (!$usageConnection->isEnabled()) {
            Console::info('Usage statistics are disabled; schema setup skipped');
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
                $usageConnection->setup();
                $health = $usageConnection->healthCheck();
                if (($health['healthy'] ?? false) !== true) {
                    throw new \RuntimeException('Usage schema health check failed');
                }
                Console::success('Usage schema is ready');
                return;
            } catch (\Throwable $th) {
                if ($attempt >= $max) {
                    throw $th;
                }
                Console::warning("Usage schema setup attempt {$attempt} failed: " . $th->getMessage());
                sleep($sleep);
            }
        }
    }
}
