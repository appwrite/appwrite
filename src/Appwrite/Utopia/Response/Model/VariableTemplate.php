<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class VariableTemplate extends Model
{
    public function __construct()
    {
        $this
        ->addRule('name', [
            'type' => self::TYPE_STRING,
            'description' => 'Variable Name.',
            'default' => '',
            'example' => 'APPWRITE_DATABASE_ID',
        ])
        ->addRule('description', [
            'type' => self::TYPE_STRING,
            'description' => 'Variable Description.',
            'default' => '',
            'example' => 'The ID of the Appwrite database that contains the collection to sync.',
        ])
        ->addRule('placeholder', [
            'type' => self::TYPE_STRING,
            'description' => 'Variable Placeholder.',
            'default' => '',
            'example' => '64a55...7b912',
        ])
        ->addRule('required', [
            'type' => self::TYPE_BOOLEAN,
            'description' => 'Is the variable required?',
            'default' => false,
            'example' => false,
        ])
        ->addRule('type', [
            'type' => self::TYPE_STRING,
            'description' => 'Variable Type.',
            'default' => '',
            'example' => 'password',
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
        return 'Variable Template';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_VARIABLE_TEMPLATE;
    }
}
