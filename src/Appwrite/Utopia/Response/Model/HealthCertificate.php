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
                'description' => 'Certificate name',
                'default' => '',
                'example' => '/CN=www.google.com',
            ])
            ->addRule('subjectSN', [
                'type' => self::TYPE_STRING,
                'description' => 'Subject SN',
                'default' => 'www.google.com',
                'example' => '',
            ])
            ->addRule('issuerOrganisation', [
                'type' => self::TYPE_STRING,
                'description' => 'Issuer organisation',
                'default' => 'Google Trust Services LLC',
                'example' => '',
            ])
            ->addRule('validFrom', [
                'type' => self::TYPE_STRING,
                'description' => 'Valid from',
                'default' => '',
                'example' => '1704200998',
            ])
            ->addRule('validTo', [
                'type' => self::TYPE_STRING,
                'description' => 'Valid to',
                'default' => '',
                'example' => '1711458597',
            ])
            ->addRule('signatureTypeSN', [
                'type' => self::TYPE_STRING,
                'description' => 'Signature type SN',
                'default' => '',
                'example' => 'RSA-SHA256',
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
