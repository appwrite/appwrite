<?php

declare(strict_types=1);

namespace Utopia\Messaging\Adapter\Email\Bird;

/**
 * Why an email is sent. Bird uses it to decide which suppressions and
 * unsubscribes may block the message.
 */
enum Category: string
{
    /**
     * Receipts, alerts and other mail the recipient's own action calls for.
     * Delivered as written, past a spam complaint or a marketing opt-out.
     */
    case TRANSACTIONAL = 'transactional';

    /**
     * Newsletters and promotions. Bird adds its unsubscribe link and holds
     * the message back for every suppression and opt-out.
     */
    case MARKETING = 'marketing';
}
