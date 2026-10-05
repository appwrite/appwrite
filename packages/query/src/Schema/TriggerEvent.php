<?php

namespace Utopia\Query\Schema;

enum TriggerEvent: string
{
    case Insert = 'INSERT';
    case Update = 'UPDATE';
    case Delete = 'DELETE';
}
