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
            ->addRule('certificateName', [
                'type' => self::TYPE_STRING,
                'description' => 'Certificate name',
                'default' => '',
                'example' => '/CN=www.google.com',
            ])
            ->addRule('certificateSubjectSN', [
                'type' => self::TYPE_STRING,
                'description' => 'Certificate subject SN',
                'default' => 'www.google.com',
                'example' => '',
            ])
            ->addRule('certificateIssuerOrganisation', [
                'type' => self::TYPE_STRING,
                'description' => 'Certificate issuer organisation',
                'default' => 'Google Trust Services LLC',
                'example' => '',
            ])
            ->addRule('certificateValidFrom', [
                'type' => self::TYPE_STRING,
                'description' => 'Certificate valid from',
                'default' => '',
                'example' => '1704200998',
            ])
            ->addRule('certificateValidTo', [
                'type' => self::TYPE_STRING,
                'description' => 'Certificate valid to',
                'default' => '',
                'example' => '1711458597',
            ])
            ->addRule('certificateSignatureTypeSN', [
                'type' => self::TYPE_STRING,
                'description' => 'Certificate signature type SN',
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
