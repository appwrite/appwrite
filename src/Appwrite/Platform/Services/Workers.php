<?php

namespace Appwrite\Platform\Services;

use Appwrite\Platform\Workers\Certificates;
use Appwrite\Platform\Workers\Deletes;
use Appwrite\Platform\Workers\Executions;
use Appwrite\Platform\Workers\Functions;
use Appwrite\Platform\Workers\Mails;
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
            ->addAction(Certificates::getName(), new Certificates())
            ->addAction(Deletes::getName(), new Deletes())
            ->addAction(Executions::getName(), new Executions())
            ->addAction(Functions::getName(), new Functions())
            ->addAction(Mails::getName(), new Mails())
            ->addAction(Messaging::getName(), new Messaging())
            ->addAction(Notifications::getName(), new Notifications())
            ->addAction(Webhooks::getName(), new Webhooks())
            ->addAction(Migrations::getName(), new Migrations())
        ;
    }
}
