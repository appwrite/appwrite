<?php

namespace Appwrite\Enum;

enum MessageStatus: string
{
    /**
     * Message that is not ready to be sent
     */
    case Draft = 'draft';
    /**
     * Scheduled to be sent for a later time
     */
    case Scheduled = 'scheduled';
    /**
     * Picked up by the worker and starting to send
     */
    case Processing = 'processing';
    /**
     * Sent without errors
     */
    case Sent = 'sent';
    /**
     * Sent with some errors
     */
    case Failed = 'failed';
}
