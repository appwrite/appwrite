<?php

declare(strict_types=1);

namespace Utopia\Storage\Exception;

/**
 * The file has not changed since the time a read asked about.
 */
class NotModifiedException extends StorageException
{
}
