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
            // ->addRule('type', [ TODO: use this when token types will be strings
            //     'type' => self::TYPE_STRING,
            //     'description' => 'Token type. Possible values: play, pause',
            //     'default' => '',
            //     'example' => '127.0.0.1',
            // ])
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