<?php

declare(strict_types=1);

namespace Utopia\Auth\Enums;

/**
 * OpenID Connect prompt values that control end-user interaction
 * during authorization requests (Core 1.0 §3.1.2.1).
 */
enum Prompt: string
{
    /** Authorization server must not display authentication or consent UI. */
    case None = 'none';

    /** Authorization server should prompt the end-user to reauthenticate. */
    case Login = 'login';

    /** Authorization server should prompt the end-user for consent. */
    case Consent = 'consent';

    /** Authorization server should prompt the end-user to select an account. */
    case SelectAccount = 'select_account';
}
