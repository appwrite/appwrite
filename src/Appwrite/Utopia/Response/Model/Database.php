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
=======
>>>>>>> 1ea664622 (remove read write permission from database model)
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
<<<<<<< HEAD
        return 'Collection';
>>>>>>> 42fb4ad3d (database response model)
=======
        return 'Database';
>>>>>>> 1ea664622 (remove read write permission from database model)
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
<<<<<<< HEAD
        return Response::MODEL_COLLECTION;
>>>>>>> 42fb4ad3d (database response model)
=======
        return Response::MODEL_DATABASE;
>>>>>>> 1ea664622 (remove read write permission from database model)
    }
}
