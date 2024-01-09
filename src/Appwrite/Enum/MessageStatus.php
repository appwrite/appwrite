<?php

namespace Appwrite\Enum;

// TODO: Convert to backed enum after upgrading to PHP 8.1
// enum MessageStatus: string
// {
//     /**
//      * Message that is not ready to be sent
//      */
//     case Draft = 'draft';
//     /**
//      * Scheduled to be sent for a later time
//      */
//     case Scheduled = 'scheduled';
//     /**
//      * Picked up by the worker and starting to send
//      */
//     case Processing = 'processing';
//     /**
//      * Sent without errors
//      */
//     case Sent = 'sent';
//     /**
//      * Sent with some errors
//      */
//     case Failed = 'failed';
// }

class MessageStatus
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
    public const PROCESSING = 'processing';
    /**
     * Sent without errors
     */
    public const SENT = 'sent';
    /**
     * Sent with some errors
     */
    public const FAILED = 'failed';
}
