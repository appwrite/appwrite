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
                'type' => 'string',
                'description' => 'Domain ID.',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('domain', [
                'type' => 'string',
                'description' => 'Domain name.',
                'example' => 'appwrite.company.com',
            ])
            ->addRule('registerable', [
                'type' => 'string',
                'description' => 'Registerable domain name.',
                'example' => 'company.com',
            ])
            ->addRule('tld', [
                'type' => 'string',
                'description' => 'TLD name.',
                'example' => 'com',
            ])
            ->addRule('verification', [
                'type' => 'boolean',
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