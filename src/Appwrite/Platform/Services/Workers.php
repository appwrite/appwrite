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
            ->addAction('certificates', Certificates::class)
            ->addAction('deletes', Deletes::class)
            ->addAction('executions', Executions::class)
            ->addAction('functions', Functions::class)
            ->addAction('mails', Mails::class)
            ->addAction('maintenance', Maintenance::class)
            ->addAction('messaging', Messaging::class)
            ->addAction('notifications', Notifications::class)
            ->addAction('webhooks', Webhooks::class)
            ->addAction('migrations', Migrations::class)
        ;
    }
}
