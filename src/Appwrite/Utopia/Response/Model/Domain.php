<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Domain extends Model
{
    /**
     * @var bool
     */
    protected $public = false;
    
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Domain ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
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