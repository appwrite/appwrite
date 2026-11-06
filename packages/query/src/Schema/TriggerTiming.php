<?php

namespace Utopia\Query\Schema;

enum TriggerTiming: string
{
    case Before = 'BEFORE';
    case After = 'AFTER';
    case InsteadOf = 'INSTEAD OF';
}
