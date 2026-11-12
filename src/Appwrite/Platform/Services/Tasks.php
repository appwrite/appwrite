<?php

namespace Appwrite\Platform\Services;

use Appwrite\Platform\Tasks\Doctor;
use Appwrite\Platform\Tasks\Encryption;
use Appwrite\Platform\Tasks\Install;
use Appwrite\Platform\Tasks\Interval;
use Appwrite\Platform\Tasks\Maintenance;
use Appwrite\Platform\Tasks\Migrate;
use Appwrite\Platform\Tasks\QueueRetry;
use Appwrite\Platform\Tasks\Schedule;
use Appwrite\Platform\Tasks\ScheduleAlerts;
use Appwrite\Platform\Tasks\ScheduleExecutions;
use Appwrite\Platform\Tasks\ScheduleFunctions;
use Appwrite\Platform\Tasks\ScheduleMaintenance;
use Appwrite\Platform\Tasks\ScheduleMessages;
use Appwrite\Platform\Tasks\Screenshot;
use Appwrite\Platform\Tasks\SDKs;
use Appwrite\Platform\Tasks\Specs;
use Appwrite\Platform\Tasks\SSL;
use Appwrite\Platform\Tasks\TimeTravel;
use Appwrite\Platform\Tasks\Upgrade;
use Appwrite\Platform\Tasks\Vars;
use Appwrite\Platform\Tasks\Version;
use Utopia\Platform\Service;

class Tasks extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_TASK;
        $this
            ->addAction('doctor', Doctor::class)
            ->addAction('encryption', Encryption::class)
            ->addAction('install', Install::class)
            ->addAction('interval', Interval::class)
            ->addAction('maintenance', Maintenance::class)
            ->addAction('migrate', Migrate::class)
            ->addAction('queue-retry', QueueRetry::class)
            ->addAction('sdks', SDKs::class)
            ->addAction('ssl', SSL::class)
            ->addAction('screenshot', Screenshot::class)
            ->addAction('schedule', Schedule::class)
            ->addAction('schedule-alerts', ScheduleAlerts::class)
            ->addAction('schedule-functions', ScheduleFunctions::class)
            ->addAction('schedule-executions', ScheduleExecutions::class)
            ->addAction('schedule-maintenance', ScheduleMaintenance::class)
            ->addAction('schedule-messages', ScheduleMessages::class)
            ->addAction('specs', Specs::class)
            ->addAction('upgrade', Upgrade::class)
            ->addAction('vars', Vars::class)
            ->addAction('version', Version::class)
            ->addAction('time-travel', TimeTravel::class)
        ;
    }
}
