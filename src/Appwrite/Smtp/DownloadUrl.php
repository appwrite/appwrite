<?php

declare(strict_types=1);

namespace Appwrite\Smtp;

use Utopia\System\System;

final class DownloadUrl
{
    /** @param array<string, string> $claims */
    public static function create(array $claims): string
    {
        $deliveryId = $claims['deliveryId'] ?? '';
        $fileId = $claims['fileId'] ?? '';
        $token = Gateway::downloadTokens()->issue($claims);
        $endpoint = rtrim(System::getEnv('_APP_SMTP_DOWNLOAD_ENDPOINT', 'http://appwrite'), '/');

        return $endpoint
            .'/v1/smtp/deliveries/'.rawurlencode($deliveryId)
            .'/files/'.rawurlencode($fileId)
            .'?token='.rawurlencode($token);
    }
}
