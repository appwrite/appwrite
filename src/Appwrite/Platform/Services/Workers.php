<?php

namespace Appwrite\Platform\Services;

use Appwrite\Platform\Workers\Certificates;
use Appwrite\Platform\Workers\Deletes;
use Appwrite\Platform\Workers\Executions;
use Appwrite\Platform\Workers\Functions;
use Appwrite\Platform\Workers\Mails;
use Appwrite\Platform\Workers\Maintenance;
use Appwrite\Platform\Workers\Messaging;
use Appwrite\Platform\Workers\Migrations;
use Appwrite\Platform\Workers\Notifications;
use Appwrite\Platform\Workers\Webhooks;
use Utopia\Platform\Service;

class Workers extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_WORKER;
        $this
            ->addAction(Certificates::getName(), Certificates::class)
            ->addAction(Deletes::getName(), Deletes::class)
            ->addAction(Executions::getName(), Executions::class)
            ->addAction(Functions::getName(), Functions::class)
            ->addAction(Mails::getName(), Mails::class)
            ->addAction(Maintenance::getName(), Maintenance::class)
            ->addAction(Messaging::getName(), Messaging::class)
            ->addAction(Notifications::getName(), Notifications::class)
            ->addAction(Webhooks::getName(), Webhooks::class)
            ->addAction(Migrations::getName(), Migrations::class)
        ;
    }
}
