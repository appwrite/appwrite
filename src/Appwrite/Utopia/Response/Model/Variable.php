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
                'description' => 'Variable ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Variable creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Variable creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
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
            ->addRule('resourceType', [
                'type' => self::TYPE_STRING,
                'description' => 'Service to which the variable belongs. Possible values are "project", "function"',
                'default' => '',
                'example' => 'function',
            ])
            ->addRule('resourceId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of resource to which the variable belongs. If resourceType is "project", it is empty. If resourceType is "function", it is ID of the function.',
                'default' => '',
                'example' => 'myAwesomeFunction',
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
