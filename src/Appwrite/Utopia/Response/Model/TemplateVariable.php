<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class TemplateVariable extends Model
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
            ->addRule('value', [
                'type' => self::TYPE_STRING,
                'description' => 'Variable Value.',
                'default' => '',
                'example' => '512',
            ])
            ->addRule('secret', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Variable secret flag. Secret variables can only be updated or deleted, but never read.',
                'default' => false,
                'example' => false,
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
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Template Variable';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_TEMPLATE_VARIABLE;
    }
}
