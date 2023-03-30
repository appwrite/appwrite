<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Domain extends Model
{
    /**
     * @var bool
     */
    protected bool $public = false;

    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Domain ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Domain creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Domain update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('domain', [
                'type' => self::TYPE_STRING,
                'description' => 'Domain name.',
                'default' => '',
                'example' => 'appwrite.company.com',
            ])
            ->addRule('registerable', [
                'type' => self::TYPE_STRING,
                'description' => 'Registerable domain name.',
                'default' => '',
                'example' => 'company.com',
            ])
            ->addRule('tld', [
                'type' => self::TYPE_STRING,
                'description' => 'TLD name.',
                'default' => '',
                'example' => 'com',
            ])
            ->addRule('verification', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Verification process status.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('certificateId', [
                'type' => self::TYPE_STRING,
                'description' => 'Certificate ID.',
                'default' => '',
                'example' => '6ejea5c13377e',
            ])
            ->addRule('registered', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Registered with Appwrite',
                'default' => false,
                'example' => true,
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
        return 'Domain';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_DOMAIN;
    }
}
