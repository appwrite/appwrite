<?php

namespace Appwrite\Platform\Tasks;

use Utopia\CLI\Console;
use Utopia\Platform\Action;
use Utopia\Queue\Client;
use Utopia\Queue\Connection;
use Utopia\Validator\Text;
use Utopia\Validator\Wildcard;

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
            ->param('name', '', new Text(100), 'Queue name')
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
