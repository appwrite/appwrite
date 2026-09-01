<?php

declare(strict_types=1);

namespace Appwrite\Smtp;

use Utopia\Http\Adapter\Swoole\Request;
use Utopia\System\System;

final class Gateway
{
    public static function authorized(Request $request): bool
    {
        $authorization = $request->getHeaderLine('authorization');
        $secret = System::getEnv('_APP_SMTP_GATEWAY_SECRET', '');

        return $secret !== ''
            && str_starts_with($authorization, 'Bearer ')
            && hash_equals($secret, substr($authorization, 7));
    }

    public static function recipientTokens(): RecipientToken
    {
        return new RecipientToken(
            System::getEnv('_APP_SMTP_GATEWAY_SECRET', ''),
            (int) System::getEnv('_APP_SMTP_RECIPIENT_TOKEN_TTL', '300'),
        );
    }

    public static function downloadTokens(): RecipientToken
    {
        return new RecipientToken(
            System::getEnv('_APP_SMTP_GATEWAY_SECRET', ''),
            (int) System::getEnv('_APP_SMTP_DOWNLOAD_TOKEN_TTL', '3600'),
        );
    }
}
