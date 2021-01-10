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
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Key name.',
                'example' => 'My API Key',
            ])
            ->addRule('scopes', [
                'type' => self::TYPE_STRING,
                'description' => 'Allowed permission scopes.',
                'default' => [],
                'example' => ['users.read', 'documents.write'],
                'array' => true,
            ])
            ->addRule('secret', [
                'type' => self::TYPE_STRING,
                'description' => 'Secret key.',
                'example' => '919c2d18fb5d4...a2ae413da83346ad2',
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
        return 'Key';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_KEY;
    }
}