<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Console\Console;
use Utopia\Platform\Action;
use Utopia\Queue\Publisher\Synchronous as Publisher;
use Utopia\Queue\Queue;
use Utopia\Validator\Text;
use Utopia\Validator\Wildcard;

class QueuePurge extends Action
{
    public static function getName(): string
    {
        return 'queue-purge';
    }

    public function __construct()
    {
        $this
            ->desc('Delete failed jobs from a specific queue identified by the name parameter')
            ->param('name', '', new Text(100), 'Queue name')
            ->param('older-than', '', new Wildcard(), 'Only delete failed jobs older than this many days. Deletes all failed jobs when omitted.', true)
            ->param('limit', '', new Wildcard(), 'Maximum number of failed jobs to purge. Purges all matching jobs when omitted.', true)
            ->inject('publisher')
            ->callback($this->action(...));
    }

    /**
     * @param string $name The name of the queue to purge failed jobs from
     * @param mixed $olderThan
     * @param mixed $limit
     * @param Publisher $publisher
     */
    public function action(string $name, mixed $olderThan, mixed $limit, Publisher $publisher): void
    {
        if (!$name) {
            Console::error('Missing required parameter $name');
            return;
        }

        if ($olderThan === null || $olderThan === '') {
            $olderThanSeconds = null;
        } elseif (\ctype_digit((string) $olderThan) && (int) $olderThan > 0) {
            $olderThanSeconds = (int) $olderThan * 86400;
        } else {
            Console::error('Parameter --older-than must be a positive whole number of days.');
            Console::exit(1);
            return;
        }

        if ($limit === null || $limit === '') {
            $limit = null;
        } elseif (\ctype_digit((string) $limit) && (int) $limit > 0) {
            $limit = (int) $limit;
        } else {
            Console::error('Parameter --limit must be a positive whole number of jobs.');
            Console::exit(1);
            return;
        }

        Console::log('Purging failed jobs...');
        $purged = $publisher->purge(new Queue($name), $olderThanSeconds, $limit);
        Console::success("Purged {$purged} failed job(s) from queue '{$name}'.");
    }
}
