<?php

namespace Appwrite\Platform\Services;

use Appwrite\Platform\Tasks\Doctor;
use Appwrite\Platform\Tasks\Install;
use Appwrite\Platform\Tasks\Interval;
use Appwrite\Platform\Tasks\Maintenance;
use Appwrite\Platform\Tasks\Migrate;
use Appwrite\Platform\Tasks\QueueRetry;
use Appwrite\Platform\Tasks\Schedule;
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
            ->addAction(Doctor::getName(), Doctor::class)
            ->addAction(Install::getName(), Install::class)
            ->addAction(Interval::getName(), Interval::class)
            ->addAction(Maintenance::getName(), Maintenance::class)
            ->addAction(Migrate::getName(), Migrate::class)
            ->addAction(QueueRetry::getName(), QueueRetry::class)
            ->addAction(SDKs::getName(), SDKs::class)
            ->addAction(SSL::getName(), SSL::class)
            ->addAction(Screenshot::getName(), Screenshot::class)
            ->addAction(Schedule::getName(), Schedule::class)
            ->addAction(ScheduleFunctions::getName(), ScheduleFunctions::class)
            ->addAction(ScheduleExecutions::getName(), ScheduleExecutions::class)
            ->addAction(ScheduleMaintenance::getName(), ScheduleMaintenance::class)
            ->addAction(ScheduleMessages::getName(), ScheduleMessages::class)
            ->addAction(Specs::getName(), Specs::class)
            ->addAction(Upgrade::getName(), Upgrade::class)
            ->addAction(Vars::getName(), Vars::class)
            ->addAction(Version::getName(), Version::class)
            ->addAction(TimeTravel::getName(), TimeTravel::class)
        ;
    }
}
