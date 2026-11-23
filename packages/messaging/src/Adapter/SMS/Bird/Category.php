<?php

declare(strict_types=1);

namespace Utopia\Messaging\Adapter\SMS\Bird;

/**
 * Content classification Bird requires on every free-text send. Carriers see
 * it, and where a destination country requires sender registration, the
 * registration is approved per category.
 */
enum Category: string
{
    /**
     * Order confirmations, alerts and other messages the recipient expects.
     */
    case TRANSACTIONAL = 'transactional';

    /**
     * Promotions. A registration approved for marketing covers every category.
     */
    case MARKETING = 'marketing';

    /**
     * One-time passcodes. Bird redacts the body on later reads.
     */
    case AUTHENTICATION = 'authentication';

    /**
     * Account and service notices.
     */
    case SERVICE = 'service';
}
