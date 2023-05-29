<?php

namespace Appwrite\Platform\Services;

use Utopia\Platform\Service;
use Appwrite\Platform\Workers\Audits;
use Appwrite\Platform\Workers\Webhooks;
use Appwrite\Platform\Workers\Mails;

class Workers extends Service
{
    public function __construct()
    {
        $this->type = self::TYPE_WORKER;
        $this
            ->addAction(Audits::getName(), new Audits())
            ->addAction(Webhooks::getName(), new Webhooks())
            ->addAction(Mails::getName(), new Mails())
        ;
    }
}
