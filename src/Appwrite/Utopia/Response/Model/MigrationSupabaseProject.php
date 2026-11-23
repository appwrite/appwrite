<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class MigrationSupabaseProject extends Model
{
    public function __construct()
    {
        $this
            ->addRule('projectRef', [
                'type' => self::TYPE_STRING,
                'description' => 'Project reference.',
                'default' => '',
                'example' => 'abcdefghijklmnopqrst',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Project name.',
                'default' => '',
                'example' => 'My Project',
            ])
            ->addRule('region', [
                'type' => self::TYPE_STRING,
                'description' => 'Project region.',
                'default' => '',
                'example' => 'us-east-1',
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Project status.',
                'default' => '',
                'example' => 'ACTIVE_HEALTHY',
            ]);
    }

    public function getName(): string
    {
        return 'MigrationSupabaseProject';
    }

    public function getType(): string
    {
        return Response::MODEL_MIGRATION_SUPABASE_PROJECT;
    }
}
