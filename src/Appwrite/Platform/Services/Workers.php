<?php

namespace Appwrite\Platform\Services;

use Utopia\Platform\Service;
use Appwrite\Platform\Workers\Audits;
use Appwrite\Platform\Workers\Webhooks;
use Appwrite\Platform\Workers\Mails;
use Appwrite\Platform\Workers\Messaging;
use Appwrite\Platform\Workers\Certificates;
use Appwrite\Platform\Workers\Databases;
use Appwrite\Platform\Workers\Usage;

class Workers extends Service
{
    public function __construct()
    {
        $this->type = self::TYPE_WORKER;
        $this
            ->addAction(Audits::getName(), new Audits())
            ->addAction(Webhooks::getName(), new Webhooks())
            ->addAction(Mails::getName(), new Mails())
            ->addAction(Messaging::getName(), new Messaging())
            ->addAction(Certificates::getName(), new Certificates())
            ->addAction(Databases::getName(), new Databases())
            //->addAction(Usage::getName(), new Usage())
        ;
    }
}
