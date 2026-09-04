<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Execution as ExecutionMessage;
use Appwrite\Event\Message\ExecutionCancelled as ExecutionCancelledMessage;
use Appwrite\Event\Message\Executions as ExecutionsMessage;

/** @extends Base<ExecutionMessage|ExecutionCancelledMessage|ExecutionsMessage> */
readonly class Execution extends Base
{
}
