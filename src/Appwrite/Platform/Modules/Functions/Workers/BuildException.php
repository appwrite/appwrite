<?php

namespace Appwrite\Platform\Modules\Functions\Workers;

use Appwrite\Extend\Exception;

class BuildException extends Exception
{
    public function __construct(
        ?string $message = null,
        string $type = Exception::BUILD_FAILED,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($type, $message, previous: $previous);
    }
}
