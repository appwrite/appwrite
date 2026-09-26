<?php

declare(strict_types=1);

namespace Utopia\Storage\Exception;

/**
 * Base type for every exception this library throws at runtime, so consumers
 * can catch any storage failure as one category. Invalid arguments (programmer
 * errors) throw SPL \InvalidArgumentException instead.
 */
class StorageException extends \Exception {}
