<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

enum RetentionPolicy: string
{
    case Limits = 'limits';
    case Interest = 'interest';
    case WorkQueue = 'workqueue';
}
