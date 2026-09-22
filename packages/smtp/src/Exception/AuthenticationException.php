<?php

declare(strict_types=1);

namespace Utopia\SMTP\Exception;

/**
 * No mechanism was shared with the server, or the credentials were refused.
 */
class AuthenticationException extends SmtpException {}
