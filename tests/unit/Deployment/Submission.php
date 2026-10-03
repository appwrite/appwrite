<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

enum Submission
{
    case LostBeforeAccepting;
    case LostAfterAccepting;
    case FailedAfterAccepting;
}
