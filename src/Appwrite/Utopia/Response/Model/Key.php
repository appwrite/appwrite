<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Key extends Model
{
    /**
     * @var bool
     */
    protected $public = false;

    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Key ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Key creation date in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Key update date in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Key name.',
                'default' => '',
                'example' => 'My API Key',
            ])
            ->addRule('expire', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Key expiration in Unix timestamp.',
                'default' => 0,
                'example' => '1653990687',
            ])
            ->addRule('scopes', [
                'type' => self::TYPE_STRING,
                'description' => 'Allowed permission scopes.',
                'default' => [],
                'example' => 'users.read',
                'array' => true,
            ])
            ->addRule('secret', [
                'type' => self::TYPE_STRING,
                'description' => 'Secret key.',
                'default' => '',
                'example' => '919c2d18fb5d4...a2ae413da83346ad2',
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
        return 'Key';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_KEY;
    }
}
