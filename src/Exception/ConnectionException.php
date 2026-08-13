<?php

declare(strict_types=1);

namespace Utopia\SMTP\Exception;

/**
 * The socket could not be opened, read, written or upgraded.
 */
class ConnectionException extends SmtpException {}
