<?php

namespace Appwrite\Event\Message;

enum DistributionSubmissionAction: string
{
    case Advance = 'advance';
    case Control = 'control';
    case Reconcile = 'reconcile';
    case Renew = 'renew';
}
