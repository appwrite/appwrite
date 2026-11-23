<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class DistributionSigningIdentityDownload extends Model
{
    public function __construct()
    {
        $this
            ->addRule('files', [
                'type' => Response::MODEL_DISTRIBUTION_SIGNING_IDENTITY_FILE,
                'description' => 'Signing identity files: the certificate and provisioning profiles for iOS, or the keystore for Android.',
                'default' => [],
                'required' => false,
                'example' => [],
                'array' => true,
            ])
            ->addRule('passwords', [
                'type' => self::TYPE_JSON,
                'description' => 'Passwords that unlock the files: `certificate` for iOS, or `storePassword` and `keyPassword` for Android.',
                'default' => [],
                'example' => ['storePassword' => 'password', 'keyPassword' => 'password'],
            ])
            ->addRule('alias', [
                'type' => self::TYPE_STRING,
                'description' => 'Key alias inside the Android keystore. Empty for iOS.',
                'default' => '',
                'example' => 'upload',
            ]);
    }

    public function getName(): string
    {
        return 'DistributionSigningIdentityDownload';
    }

    public function getType(): string
    {
        return Response::MODEL_DISTRIBUTION_SIGNING_IDENTITY_DOWNLOAD;
    }
}
