<?php

namespace Appwrite\Platform\Modules\Functions\Workers;

use Appwrite\Extend\Exception;

/**
 * A build failure whose message is safe to surface in user-visible build logs.
 *
 * Every build error the worker raises intentionally is a BuildException, so the
 * failure handler can show its message to users while masking any other
 * throwable (e.g. database or executor failures) behind a generic message.
 */
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
