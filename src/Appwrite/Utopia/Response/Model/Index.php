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
                'type' => self::TYPE_JSON,
                'description' => 'Index attributes.',
                'default' => [],
                'example' => [],
            ])
            ->addRule('lengths', [
                'type' => self::TYPE_JSON,
                'description' => 'Index lengths.',
                'default' => [],
                'example' => [],
            ])
            ->addRule('orders', [
                'type' => self::TYPE_JSON,
                'description' => 'Index orders.',
                'default' => [],
                'example' => [],
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