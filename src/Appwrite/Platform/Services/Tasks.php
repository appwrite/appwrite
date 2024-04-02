<?php

namespace Appwrite\Platform\Services;

use Appwrite\Platform\Tasks\DevGenerateTranslations;
use Appwrite\Platform\Tasks\Doctor;
use Appwrite\Platform\Tasks\Install;
use Appwrite\Platform\Tasks\Maintenance;
use Appwrite\Platform\Tasks\Migrate;
use Appwrite\Platform\Tasks\QueueCount;
use Appwrite\Platform\Tasks\QueueRetry;
use Appwrite\Platform\Tasks\ScheduleFunctions;
use Appwrite\Platform\Tasks\ScheduleMessages;
use Appwrite\Platform\Tasks\SDKs;
use Appwrite\Platform\Tasks\Specs;
use Appwrite\Platform\Tasks\SSL;
use Appwrite\Platform\Tasks\Upgrade;
use Appwrite\Platform\Tasks\Vars;
use Appwrite\Platform\Tasks\Version;
use Utopia\Platform\Service;

class Tasks extends Service
{
    public function __construct()
    {
        $this->type = self::TYPE_CLI;
        $this
            ->addAction(DevGenerateTranslations::getName(), new DevGenerateTranslations())
            ->addAction(Doctor::getName(), new Doctor())
            ->addAction(Install::getName(), new Install())
            ->addAction(Maintenance::getName(), new Maintenance())
            ->addAction(Migrate::getName(), new Migrate())
            ->addAction(QueueCount::getName(), new QueueCount())
            ->addAction(QueueRetry::getName(), new QueueRetry())
            ->addAction(SDKs::getName(), new SDKs())
            ->addAction(SSL::getName(), new SSL())
            ->addAction(ScheduleFunctions::getName(), new ScheduleFunctions())
            ->addAction(ScheduleMessages::getName(), new ScheduleMessages())
            ->addAction(Specs::getName(), new Specs())
            ->addAction(Upgrade::getName(), new Upgrade())
            ->addAction(Vars::getName(), new Vars())
            ->addAction(Version::getName(), new Version())
        ;
    }
}
