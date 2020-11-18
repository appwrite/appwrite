<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Token extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Token ID.',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('secret', [
                'type' => self::TYPE_STRING,
                'description' => 'Token secret key. This will return an empty string unless the response is returned using an API key or as part of a webhook payload.',
                'default' => 0,
                'example' => '',
            ])
            ->addRule('expire', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Token expiration date in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
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
        return 'Token';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_TOKEN;
    }
}