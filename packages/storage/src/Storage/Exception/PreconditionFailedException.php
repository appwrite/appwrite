<?php

declare(strict_types=1);

namespace Utopia\Storage\Exception;

/**
 * A conditional operation was refused: the file no longer carries the ETag the
 * caller named, or a file appeared where the caller expected none.
 */
class PreconditionFailedException extends StorageException {}
