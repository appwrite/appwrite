<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class MigrationSupabaseCredentials extends Model
{
    public function __construct()
    {
        $this
            ->addRule('endpoint', [
                'type' => self::TYPE_STRING,
                'description' => 'Supabase project endpoint.',
                'default' => '',
                'example' => 'https://abcdefghijklmnopqrst.supabase.co',
            ])
            ->addRule('apiKey', [
                'type' => self::TYPE_STRING,
                'description' => 'Supabase secret API key.',
                'default' => '',
                'example' => 'sb_secret_...',
            ])
            ->addRule('databaseHost', [
                'type' => self::TYPE_STRING,
                'description' => 'Database pooler host.',
                'default' => '',
                'example' => 'aws-0-us-east-1.pooler.supabase.com',
            ])
            ->addRule('port', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Database port.',
                'default' => 5432,
                'example' => 5432,
            ])
            ->addRule('username', [
                'type' => self::TYPE_STRING,
                'description' => 'Temporary database username.',
                'default' => '',
                'example' => 'appwrite_migration_0123456789abcdef.abcdefghijklmnopqrst',
            ])
            ->addRule('password', [
                'type' => self::TYPE_STRING,
                'description' => 'Temporary database password.',
                'default' => '',
                'example' => 'password',
            ])
            ->addRule('expire', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Time the database credentials stop working in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ]);
    }

    public function getName(): string
    {
        return 'MigrationSupabaseCredentials';
    }

    public function getType(): string
    {
        return Response::MODEL_MIGRATION_SUPABASE_CREDENTIALS;
    }
}
