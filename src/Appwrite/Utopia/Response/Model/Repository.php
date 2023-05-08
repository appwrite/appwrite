<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Repository extends Model
{
    public function __construct()
    {
        $this
            ->addRule('id', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Repository ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Repository Name.',
                'default' => '',
                'example' => 'appwrite',
            ])
            ->addRule('owner', [
                'type' => self::TYPE_JSON,
                'description' => 'Repository Owner.',
                'default' => '',
                'example' => '{"login": "Example Owner"}',
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Repository';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_REPOSITORY;
    }
}
