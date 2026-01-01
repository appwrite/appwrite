<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\Queue;

use Appwrite\Extend\Exception;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

abstract class Base extends Action
{
    use HTTP;

    protected function assertQueueThreshold(int $size, int $threshold): void
    {
        if ($size >= $threshold) {
            throw new Exception(Exception::HEALTH_QUEUE_SIZE_EXCEEDED, "Queue size threshold hit. Current size is {$size} and threshold is {$threshold}.");
        }
    }

    protected function assertFailedQueueThreshold(int $failed, int $threshold): void
    {
        if ($failed >= $threshold) {
            throw new Exception(Exception::HEALTH_QUEUE_SIZE_EXCEEDED, "Queue failed jobs threshold hit. Current size is {$failed} and threshold is {$threshold}.");
        }
    }
}
