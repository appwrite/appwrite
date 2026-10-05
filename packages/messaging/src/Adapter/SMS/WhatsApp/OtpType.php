<?php

declare(strict_types=1);

namespace Utopia\Messaging\Adapter\SMS\WhatsApp;

/**
 * How the recipient moves the code from WhatsApp into the app.
 */
enum OtpType: string
{
    /**
     * A button copies the code to the clipboard. Works on every platform and needs no app integration.
     */
    case COPY_CODE = 'copy_code';

    /**
     * A button hands the code to the Android app named in the template. Other platforms fall back to copy code.
     */
    case ONE_TAP = 'one_tap';

    /**
     * WhatsApp delivers the code to the Android app without a tap. Other platforms fall back to copy code.
     */
    case ZERO_TAP = 'zero_tap';

    /**
     * Whether Meta requires the template to name the Android apps that receive the code.
     */
    public function requiresApps(): bool
    {
        return $this !== self::COPY_CODE;
    }
}
