<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class DistributionSigningIdentityFile extends Model
{
    public function __construct()
    {
        $this
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'File name, such as `certificate.p12`, `keystore.jks` or `<bundle identifier>.mobileprovision`.',
                'default' => '',
                'example' => 'certificate.p12',
            ])
            ->addRule('content', [
                'type' => self::TYPE_STRING,
                'description' => 'File content, base64-encoded.',
                'default' => '',
                'example' => 'MIIKcQIBAzCCCjcGCSqGSIb3DQEHAaCCCigEggokMIIKIDCCBNc=',
            ]);
    }

    public function getName(): string
    {
        return 'DistributionSigningIdentityFile';
    }

    public function getType(): string
    {
        return Response::MODEL_DISTRIBUTION_SIGNING_IDENTITY_FILE;
    }
}
