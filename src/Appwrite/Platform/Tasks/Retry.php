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
        return 'retry';
    }


    public function __construct()
    {
        $this
            ->desc('Retry Queue')
            ->param('name', '', new Text(128), 'Queue name')
            ->inject('queue')
            ->callback(fn ($queueName, $queue) => $this->action($queueName, $queue));
    }

    public function action(string $queueName, Connection $queue): void
    {
        $queueClient = new Client($queueName, $queue);

        if ($queueClient->sumFailedJobs() === 0) {
            Console::error('Found no jobs to retry, are you sure you have the right queue name?');
            return;
        }

        $queueClient->retry();
    }
}
