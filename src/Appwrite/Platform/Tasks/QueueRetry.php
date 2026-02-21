<?php

namespace Appwrite\Platform\Tasks;

use Utopia\CLI\Console;
use Utopia\Platform\Action;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;
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
            ->inject('publisher')
            ->callback($this->action(...));
    }

    /**
     * @param string $name The name of the queue to retry jobs from
     * @param  mixed $limit
     * @param Publisher $publisher
     */
    public function action(string $name, mixed $limit, Publisher $publisher): void
    {
        if (!$name) {
            Console::error('Missing required parameter $name');
            return;
        }

        $limit = (int)$limit;
        Console::log('Retrying failed jobs...');
        $publisher->retry(new Queue($name), $limit);
    }
}
