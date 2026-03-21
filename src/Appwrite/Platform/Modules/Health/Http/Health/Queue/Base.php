<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\Queue;

use Appwrite\Extend\Exception;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

abstract class Base extends Action
{
    use HTTP;

    protected function assertQueueThreshold(int $size, int $threshold, bool $failed = false): void
    {
        if ($size >= $threshold) {
            $context = $failed ? 'failed jobs' : 'jobs';
            throw new Exception(Exception::HEALTH_QUEUE_SIZE_EXCEEDED, "Queue {$context} threshold hit. Current value is {$size} and threshold is {$threshold}.");
        }
    }
}
