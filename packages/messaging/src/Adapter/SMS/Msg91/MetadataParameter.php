<?php

declare(strict_types=1);

namespace Utopia\Messaging\Adapter\SMS\Msg91;

enum MetadataParameter: string
{
    /**
     * Returned in Webhook v2 callbacks to correlate MSG91 responses with the original request.
     */
    case CLIENT_ID = 'clientId';

    /**
     * Request-level tracking ID returned in delivery reports and webhook callbacks.
     */
    case CRQID = 'CRQID';

    /**
     * Alternative request-level tracking ID returned in delivery reports and webhook callbacks.
     */
    case UUID = 'UUID';
}
