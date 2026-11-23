<?php

namespace Appwrite\Event\Message;

enum DistributionBuildAction: string
{
    case Dispatch = 'dispatch';
    case Inspect = 'inspect';
    case Event = 'event';
    case Reconcile = 'reconcile';
}
