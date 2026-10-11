<?php

declare(strict_types=1);

namespace Utopia\Auth\Passkeys;

/**
 * A ceremony that must not complete: malformed input, a failed check, or a forged or replayed credential.
 */
class Exception extends \Exception
{
}
