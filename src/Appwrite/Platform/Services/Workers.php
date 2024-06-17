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
use Appwrite\Platform\Workers\SyncIn;
use Appwrite\Platform\Workers\SyncOutAggregation;
use Appwrite\Platform\Workers\SyncOutDelivery;
use Appwrite\Platform\Workers\Usage;
use Appwrite\Platform\Workers\UsageDump;
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
            ->addAction(UsageDump::getName(), new UsageDump())
            ->addAction(Usage::getName(), new Usage())
            ->addAction(Migrations::getName(), new Migrations())
            ->addAction(SyncIn::getName(), new SyncIn())
            ->addAction(SyncOutAggregation::getName(), new SyncOutAggregation())
            ->addAction(SyncOutDelivery::getName(), new SyncOutDelivery())

        ;
    }
}
