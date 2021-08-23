<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Index extends Model
{
    public function __construct()
    {
        $this
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Index Key.',
                'default' => '',
                'example' => 'index1',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Index type.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Index status. Possible values: `available`, `processing`, `deleting`, or `failed`',
                'default' => '',
                'example' => 'string',
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
