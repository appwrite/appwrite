<?php

namespace Utopia\Query\Schema;

enum ForeignKeyAction: string
{
    case Cascade = 'cascade';
    case SetNull = 'setNull';
    case SetDefault = 'setDefault';
    case Restrict = 'restrict';
    case NoAction = 'noAction';

    public function toSql(): string
    {
        return match ($this) {
            self::Cascade => 'CASCADE',
            self::SetNull => 'SET NULL',
            self::SetDefault => 'SET DEFAULT',
            self::Restrict => 'RESTRICT',
            self::NoAction => 'NO ACTION',
        };
    }
}
