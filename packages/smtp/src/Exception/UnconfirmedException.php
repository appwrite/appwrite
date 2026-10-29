<?php

declare(strict_types=1);

namespace Utopia\SMTP\Exception;

/**
 * The message was submitted and the server's decision never arrived.
 *
 * The terminating dot was written. A lost or unreadable reply is not evidence
 * the server refused the message, so sending it again can deliver it twice.
 */
class UnconfirmedException extends SmtpException
{
}
