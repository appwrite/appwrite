<?php

declare(strict_types=1);

namespace Utopia\SMTP\Exception;

/**
 * The message could not be produced, such as an attachment that stopped being readable.
 */
class MessageException extends SmtpException {}
