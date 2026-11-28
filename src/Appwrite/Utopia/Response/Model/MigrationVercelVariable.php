<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class MigrationVercelVariable extends Model
{
    public function __construct()
    {
        $this
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Variable key.',
                'default' => '',
                'example' => 'API_URL',
            ])
            ->addRule('value', [
                'type' => self::TYPE_STRING,
                'description' => 'Variable value, empty for a sensitive variable Vercel does not reveal.',
                'default' => '',
                'example' => 'https://example.com',
            ])
            ->addRule('secret', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the variable is sensitive on Vercel, so its value has to be filled in again.',
                'default' => false,
                'example' => false,
            ]);
    }

    public function getName(): string
    {
        return 'MigrationVercelVariable';
    }

    public function getType(): string
    {
        return Response::MODEL_MIGRATION_VERCEL_VARIABLE;
    }
}
