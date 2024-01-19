<?php

namespace Appwrite\Platform\Tasks;

use Utopia\CLI\Console;
use Utopia\Platform\Action;
use Utopia\Queue\Client;
use Utopia\Queue\Connection;
use Utopia\Validator\Text;

class Retry extends Action
{
    public static function getName(): string
    {
        return 'retry-jobs';
    }


    public function __construct()
    {
        $this
            ->desc('Retry failed jobs from a specific queue identified by the name parameter')
            ->param('name', '', new Text(128), 'Queue name')
            ->inject('queue')
            ->callback(fn ($name, $queue) => $this->action($name, $queue));
    }

    /**
     * @param string $name The name of the queue to retry jobs from
     * @param Connection $queue
     */
    public function action(string $name, Connection $queue): void
    {
        if (!$name) {
            Console::error('Missing required parameter $name');
            return;
        }

        $queueClient = new Client($name, $queue);

        if ($queueClient->countFailedJobs() === 0) {
            Console::error('No failed jobs found.');
            return;
        }

        $queueClient->retry();
    }
}
