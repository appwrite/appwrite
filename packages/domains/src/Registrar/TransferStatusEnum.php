<?php

declare(strict_types=1);

namespace Utopia\Domains\Registrar;

enum TransferStatusEnum: string
{
    case Transferrable = 'transferrable';
    case NotTransferrable = 'not_transferrable';
    case PendingOwner = 'pending_owner';
    case PendingAdmin = 'pending_admin';
    case PendingRegistry = 'pending_registry';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case ServiceUnavailable = 'service_unavailable';
}
