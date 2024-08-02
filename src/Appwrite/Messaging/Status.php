<?php

namespace Appwrite\Messaging;

class Status
{
    /**
     * Message that is not ready to be sent
     */
    public const DRAFT = 'draft';
    /**
     * Scheduled to be sent for a later time
     */
    public const SCHEDULED = 'scheduled';
    /**
     * Picked up by the worker and starting to send
     */
    public const SENDING = 'sending';
    /**
     * Sent without errors
     */
    public const DELIVERED = 'delivered';
    /**
     * Sent with some errors
     */
    public const FAILED = 'failed';
}
