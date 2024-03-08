<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Event;
use Utopia\CLI\Console;
use Utopia\Http\Validator\WhiteList;
use Utopia\Http\Validator\Wildcard;
use Utopia\Platform\Action;
use Utopia\Queue\Client;
use Utopia\Queue\Connection;

class QueueRetry extends Action
{
    public static function getName(): string
    {
        return 'queue-retry';
    }


    public function __construct()
    {
        $this
            ->desc('Retry failed jobs from a specific queue identified by the name parameter')
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
            ->param('limit', 0, new Wildcard(), 'jobs limit', true)
            ->inject('queue')
            ->callback(fn ($name, $limit, $queue) => $this->action($name, $limit, $queue));
    }

    /**
     * @param string $name The name of the queue to retry jobs from
     * @param  mixed $limit
     * @param Connection $queue
     */
    public function action(string $name, mixed $limit, Connection $queue): void
    {

        if (!$name) {
            Console::error('Missing required parameter $name');
            return;
        }

        $limit = (int)$limit;
        $queueClient = new Client($name, $queue);

        if ($queueClient->countFailedJobs() === 0) {
            Console::error('No failed jobs found.');
            return;
        }

        Console::log('Retrying failed jobs...');

        $queueClient->retry($limit);
    }
}
