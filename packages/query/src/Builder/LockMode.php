<?php

namespace Utopia\Query\Builder;

enum LockMode: string
{
    case ForUpdate = 'FOR UPDATE';
    case ForShare = 'FOR SHARE';
    case ForUpdateSkipLocked = 'FOR UPDATE SKIP LOCKED';
    case ForUpdateNoWait = 'FOR UPDATE NOWAIT';
    case ForShareSkipLocked = 'FOR SHARE SKIP LOCKED';
    case ForShareNoWait = 'FOR SHARE NOWAIT';

    public function toSql(): string
    {
        return $this->value;
    }
}
