<?php

declare(strict_types=1);

namespace Utopia\SMTP\Exception;

/**
 * Base type for every failure this library raises at runtime, so a caller can
 * catch anything the protocol threw at it as one category.
 *
 * Bad arguments are not among them. Passing an address that is not an address,
 * or a header the message owns, is a mistake in the calling code rather than
 * something that went wrong, and raises the SPL \InvalidArgumentException.
 * Using a transport that was never connected raises \LogicException.
 */
class SmtpException extends \Exception {}
