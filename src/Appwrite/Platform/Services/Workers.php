<?php

namespace Appwrite\Platform\Services;

use Appwrite\Platform\Workers\Audits;
use Appwrite\Platform\Workers\Builds;
use Appwrite\Platform\Workers\Certificates;
use Appwrite\Platform\Workers\Databases;
use Appwrite\Platform\Workers\Deletes;
use Appwrite\Platform\Workers\Functions;
use Appwrite\Platform\Workers\Mails;
use Appwrite\Platform\Workers\Messaging;
use Appwrite\Platform\Workers\Migrations;
use Appwrite\Platform\Workers\StatsResources;
use Appwrite\Platform\Workers\StatsUsage;
use Appwrite\Platform\Workers\StatsUsageDump;
use Appwrite\Platform\Workers\Webhooks;
use Utopia\Platform\Service;

class Workers extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_WORKER;
        $this
            ->addAction(Audits::getName(), new Audits())
            ->addAction(Builds::getName(), new Builds())
            ->addAction(Certificates::getName(), new Certificates())
            ->addAction(Databases::getName(), new Databases())
            ->addAction(Deletes::getName(), new Deletes())
            ->addAction(Functions::getName(), new Functions())
            ->addAction(Mails::getName(), new Mails())
            ->addAction(Messaging::getName(), new Messaging())
            ->addAction(Webhooks::getName(), new Webhooks())
            ->addAction(StatsUsageDump::getName(), new StatsUsageDump())
            ->addAction(StatsUsage::getName(), new StatsUsage())
            ->addAction(Migrations::getName(), new Migrations())
            ->addAction(StatsResources::getName(), new StatsResources())
        ;
    }
}
