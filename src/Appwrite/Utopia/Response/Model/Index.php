<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Index extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Index ID.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Index type.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('attributes', [
                'type' => self::TYPE_STRING,
                'description' => 'Index attributes.',
                'default' => [],
                'example' => [],
                'array' => true,
            ])
            ->addRule('orders', [
                'type' => self::TYPE_STRING,
                'description' => 'Index orders.',
                'default' => [],
                'example' => [],
                'array' => true,
                'required' => false,
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
        return 'Index';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_INDEX;
    }
}
