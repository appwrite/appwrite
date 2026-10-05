<?php

declare(strict_types=1);

namespace Utopia\Queue\Publisher;

enum Outcome: string
{
    case Published = 'published';
    case Coalesced = 'coalesced';
}
