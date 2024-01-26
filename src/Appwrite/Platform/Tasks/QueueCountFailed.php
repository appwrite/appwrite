<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Event;
use Utopia\CLI\Console;
use Utopia\Platform\Action;
use Utopia\Queue\Client;
use Utopia\Queue\Connection;
use Utopia\Validator\WhiteList;

class QueueCountFailed extends Action
{
    public static function getName(): string
    {
        return 'queue-count-failed';
    }


    public function __construct()
    {
        $this
            ->desc('Return the number of failed jobs from a specific queue identified by the name parameter')
            ->param('name', '', new WhiteList([
                Event::DATABASE_QUEUE_NAME,
                Event::DELETE_QUEUE_NAME,
                Event::AUDITS_QUEUE_NAME,
                Event::MAILS_QUEUE_NAME,
                Event::FUNCTIONS_QUEUE_NAME,
                Event::USAGE_QUEUE_NAME,
                Event::WEBHOOK_CLASS_NAME,
                Event::CERTIFICATES_QUEUE_NAME,
                Event::BUILDS_QUEUE_NAME,
                Event::MESSAGING_QUEUE_NAME,
                Event::MIGRATIONS_QUEUE_NAME,
                Event::HAMSTER_CLASS_NAME
            ]), 'Queue name')
            ->inject('queue')
            ->callback(fn ($name, $queue) => $this->action($name, $queue));
    }

    /**
     * @param string $name The name of the queue to count the failed jobs from
     * @param Connection $queue
     */
    public function action(string $name, Connection $queue): void
    {
        if (!$name) {
            Console::error('Missing required parameter $name');
            return;
        }

        $queueClient = new Client($name, $queue);

        Console::log("Queue: '" . $name . "' Currently has " . $queueClient->countFailedJobs() . " failed jobs.");
    }
}
