<?php

namespace Appwrite\Platform\Modules\Analytics\Tasks;

use Appwrite\Platform\Modules\Analytics\Storage\ClickHouse;
use Utopia\Console;
use Utopia\Platform\Action;

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
            ->inject('analyticsStorage')
            ->callback($this->action(...));
    }

    public function action(ClickHouse $analyticsStorage): void
    {
        Console::info('Setting up analytics ClickHouse schema...');
        $analyticsStorage->setup();
        Console::success('Analytics schema ready.');
    }
}
