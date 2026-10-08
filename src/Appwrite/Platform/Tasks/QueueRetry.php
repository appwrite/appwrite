<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Console;
use Utopia\Platform\Action;
use Utopia\Queue\Publisher\Synchronous as Publisher;
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
            ->param('limit', '', new Wildcard(), 'Maximum number of failed jobs to retry. Retries all of them when omitted.', true)
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

        // Every broker reads a null limit as "no cap" and 0 as "retry nothing",
        // so an omitted --limit must stay null rather than be cast to 0.
        if ($limit === null || $limit === '') {
            $limit = null;
        } elseif (\ctype_digit((string) $limit) && (int) $limit > 0) {
            $limit = (int) $limit;
        } else {
            Console::error('Parameter --limit must be a positive whole number of jobs.');
            Console::exit(1);
            return;
        }

        Console::log('Retrying failed jobs...');
        $publisher->retry(new Queue($name), $limit);
    }
}
