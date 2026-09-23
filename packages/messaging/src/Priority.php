<?php

declare(strict_types=1);

namespace Utopia\Messaging;

enum Priority: int
{
    case NORMAL = 0;
    case HIGH = 1;
}
