<?php

namespace Appwrite\Platform\Tasks;

use Utopia\CLI\Console;
use Utopia\Platform\Action;
use Utopia\Queue\Client;
use Utopia\Queue\Connection;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class QueueCount extends Action
{
    public static function getName(): string
    {
        return 'queue-count';
    }


    public function __construct()
    {
        $this
            ->desc('Return the number of from a specific queue identified by the name parameter with a specific type')
            ->param('name', '', new Text(100), 'Queue name')
            ->param('type', '', new WhiteList([
                'success',
                'failed',
                'processing',
            ]), 'Queue type')
            ->inject('queue')
            ->callback(fn ($name, $type, $queue) => $this->action($name, $type, $queue));
    }

    /**
     * @param string $name The name of the queue to count the jobs from
     * @param string $type The type of jobs to count
     * @param Connection $queue
     */
    public function action(string $name, string $type, Connection $queue): void
    {
        if (!$name) {
            Console::error('Missing required parameter $name');
            return;
        }

        $queueClient = new Client($name, $queue);

        $count = match ($type) {
            'success' => $queueClient->countSuccessfulJobs(),
            'failed' => $queueClient->countFailedJobs(),
            'processing' => $queueClient->countProcessingJobs(),
            default => 0
        };

        Console::log("Queue: '{$name}' has {$count} {$type} jobs.");
    }
}
