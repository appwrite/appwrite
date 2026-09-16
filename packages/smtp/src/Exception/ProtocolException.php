<?php

declare(strict_types=1);

namespace Utopia\SMTP\Exception;

/**
 * The server said something that is not a reply, or not one this command allows.
 *
 * The stream is no longer aligned with the protocol after this, so the
 * client drops the connection rather than reading the rest of a reply as the
 * next one.
 */
class ProtocolException extends SmtpException {}
