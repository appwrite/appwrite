<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Database extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Database ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$read', [
                'type' => self::TYPE_STRING,
                'description' => 'Collection read permissions.',
                'default' => '',
                'example' => 'role:all',
                'array' => true
            ])
            ->addRule('$write', [
                'type' => self::TYPE_STRING,
                'description' => 'Collection write permissions.',
                'default' => '',
                'example' => 'user:608f9da25e7e1',
                'array' => true
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Database name.',
                'default' => '',
                'example' => 'My Database',
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
        return 'Collection';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_COLLECTION;
    }
}
