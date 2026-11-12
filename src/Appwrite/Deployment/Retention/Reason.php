<?php

namespace Appwrite\Deployment\Retention;

enum Reason: string
{
    case Requested = 'requested';
    case Policy = 'policy';
    case Count = 'count';
}
