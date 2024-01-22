<?php

namespace Appwrite\Platform\Services;

use Utopia\Platform\Service;
use Appwrite\Platform\Workers\Audits;
use Appwrite\Platform\Workers\Webhooks;
use Appwrite\Platform\Workers\Mails;
use Appwrite\Platform\Workers\Messaging;
use Appwrite\Platform\Workers\Certificates;
use Appwrite\Platform\Workers\Databases;
use Appwrite\Platform\Workers\Functions;
use Appwrite\Platform\Workers\Builds;
use Appwrite\Platform\Workers\Deletes;
use Appwrite\Platform\Workers\Hamster;
use Appwrite\Platform\Workers\Migrations;

class Workers extends Service
{
    public function __construct()
    {
        $this->type = self::TYPE_WORKER;
        $this
            ->addAction(Audits::getName(), new Audits())
            ->addAction(Builds::getName(), new Builds())
            ->addAction(Certificates::getName(), new Certificates())
            ->addAction(Databases::getName(), new Databases())
            ->addAction(Deletes::getName(), new Deletes())
            ->addAction(Functions::getName(), new Functions())
            ->addAction(Hamster::getName(), new Hamster())
            ->addAction(Mails::getName(), new Mails())
            ->addAction(Messaging::getName(), new Messaging())
            ->addAction(Migrations::getName(), new Migrations())
            ->addAction(Webhooks::getName(), new Webhooks())

        ;
    }
}
