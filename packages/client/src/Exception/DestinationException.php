<?php

declare(strict_types=1);

namespace Utopia\Client\Exception;

/**
 * The transport connected to an address the adapter's Destinations do not allow, and
 * sent nothing.
 */
class DestinationException extends ConnectionException
{
}
