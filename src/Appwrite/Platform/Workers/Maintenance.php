<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Message\Maintenance as MaintenanceMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Maintenance\Sweep;
use Utopia\Database\Database;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Span\Span;
use Utopia\System\System;

class Maintenance extends Action
{
    public static function getName(): string
    {
        return 'maintenance';
    }

    public function __construct()
    {
        $this
            ->desc('Maintenance worker')
            ->inject('message')
            ->inject('dbForPlatform')
            ->inject('publisherForDeletes')
            ->callback($this->action(...));
    }

    public function action(Message $message, Database $dbForPlatform, DeletePublisher $publisherForDeletes): void
    {
        $maintenance = MaintenanceMessage::fromArray($message->getPayload());

        Span::add('maintenance.slot', $maintenance->slot);
        Span::add('maintenance.due', $maintenance->due);

        (new Sweep($dbForPlatform, $publisherForDeletes, System::getEnv('_APP_REGION', 'default')))($maintenance);
    }
}
