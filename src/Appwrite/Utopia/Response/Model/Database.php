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
<<<<<<< HEAD
=======
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
>>>>>>> 42fb4ad3d (database response model)
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
<<<<<<< HEAD
    public function getName(): string
    {
        return 'Database';
=======
    public function getName():string
    {
        return 'Collection';
>>>>>>> 42fb4ad3d (database response model)
    }

    /**
     * Get Type
     *
     * @return string
     */
<<<<<<< HEAD
    public function getType(): string
    {
        return Response::MODEL_DATABASE;
=======
    public function getType():string
    {
        return Response::MODEL_COLLECTION;
>>>>>>> 42fb4ad3d (database response model)
    }
}
