<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Domain extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Domain ID.',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('domain', [
                'type' => self::TYPE_STRING,
                'description' => 'Domain name.',
                'example' => 'appwrite.company.com',
            ])
            ->addRule('registerable', [
                'type' => self::TYPE_STRING,
                'description' => 'Registerable domain name.',
                'example' => 'company.com',
            ])
            ->addRule('tld', [
                'type' => self::TYPE_STRING,
                'description' => 'TLD name.',
                'example' => 'com',
            ])
            ->addRule('verification', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Verification process status.',
                'example' => true,
            ])
        ;
    }

    /**
     * Get Name
     * 
     * @return string
     */
    public function getName():string
    {
        return 'Domain';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_DOMAIN;
    }
}