<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Variable extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Function ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Function creation date in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Function update date in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Variable key.',
                'default' => '',
                'example' => 'API_KEY',
                'array' => false,
            ])
            ->addRule('value', [
                'type' => self::TYPE_STRING,
                'description' => 'Variable value.',
                'default' => '',
                'example' => 'myPa$$word1',
            ])
            ->addRule('functionId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of function variable is scoped for.',
                'default' => '',
                'example' => '5e5ea5c16897e',
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
        return 'Variable';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_VARIABLE;
    }
}
