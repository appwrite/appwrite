<?php

declare(strict_types=1);

namespace Utopia\SMTP\Exception;

/**
 * The server cannot carry this message.
 *
 * Nothing has failed: the server said what it can do, and this message
 * needs more. Raised before anything is sent, and never worth retrying
 * against the same server.
 */
class CapabilityException extends SmtpException {}
