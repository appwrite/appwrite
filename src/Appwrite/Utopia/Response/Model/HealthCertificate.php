<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class HealthCertificate extends Model
{
    public function __construct()
    {
        $this
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Name of the service.',
                'default' => '',
                'example' => 'database',
            ])
            ->addRule('payload', [
                'type' => self::TYPE_JSON,
                'description' => 'Certificate information payload',
                'default' => [],
                'example' => [
                    'name' => '/CN=www.google.com',
                    'validFrom' => '1704200998',
                    'validTo' => '1711458597',
                    'signatureTypeSN' => 'RSA-SHA256',
                ],
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Health Certificate';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_HEALTH_CERTIFICATE;
    }
}
