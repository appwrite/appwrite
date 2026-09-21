<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Migration\Resource;

class MigrationReport extends Model
{
    public function __construct()
    {
        $this
            ->addRule(Resource::TYPE_USER, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of users to be migrated.',
                'default' => 0,
                'example' => 20,
            ])
            ->addRule(Resource::TYPE_TEAM, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of teams to be migrated.',
                'default' => 0,
                'example' => 20,
            ])
            ->addRule(Resource::TYPE_DATABASE, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of databases to be migrated.',
                'default' => 0,
                'example' => 20,
            ])
            ->addRule(Resource::TYPE_ROW, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of rows to be migrated.',
                'default' => 0,
                'example' => 20,
            ])
            ->addRule(Resource::TYPE_FILE, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of files to be migrated.',
                'default' => 0,
                'example' => 20,
            ])
            ->addRule(Resource::TYPE_BUCKET, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of buckets to be migrated.',
                'default' => 0,
                'example' => 20,
            ])
            ->addRule(Resource::TYPE_FUNCTION, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of functions to be migrated.',
                'default' => 0,
                'example' => 20,
            ])
            ->addRule(Resource::TYPE_PLATFORM, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of platforms to be migrated.',
                'default' => 0,
                'example' => 5,
            ])
            ->addRule(Resource::TYPE_API_KEY, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of API keys to be migrated.',
                'default' => 0,
                'example' => 5,
            ])
            ->addRule(Resource::TYPE_PROJECT_VARIABLE, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of project variables to be migrated.',
                'default' => 0,
                'example' => 5,
            ])
            ->addRule(Resource::TYPE_WEBHOOK, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of webhooks to be migrated.',
                'default' => 0,
                'example' => 5,
            ])
            ->addRule(Resource::TYPE_AUTH_METHODS, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of auth-method configs to be migrated (always 0 or 1 — the project-level flag bundle).',
                'default' => 0,
                'example' => 1,
            ])
            ->addRule(Resource::TYPE_PROJECT_PROTOCOLS, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of protocol configs to be migrated (always 0 or 1 — the project-level REST/GraphQL/WebSocket flags).',
                'default' => 0,
                'example' => 1,
            ])
            ->addRule(Resource::TYPE_PROJECT_LABELS, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of label sets to be migrated (always 0 or 1 — the project-level RBAC label array).',
                'default' => 0,
                'example' => 1,
            ])
            ->addRule(Resource::TYPE_PROJECT_SERVICES, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of service configs to be migrated (always 0 or 1 — the project-level enable/disable flags for all 17 services).',
                'default' => 0,
                'example' => 1,
            ])
            ->addRule(Resource::TYPE_POLICIES, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of policy bundles to be migrated (always 0 or 1 — the project-level security policies covering password rules, session behavior, user limits, and membership privacy).',
                'default' => 0,
                'example' => 1,
            ])
            ->addRule(Resource::TYPE_SMTP, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of SMTP configurations to be migrated (always 0 or 1 — the project-level custom SMTP settings; password is not exposed by the source API).',
                'default' => 0,
                'example' => 1,
            ])
            ->addRule(Resource::TYPE_RULE, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of custom-domain proxy rules to be migrated. Auto-generated `.appwrite.network` rules are skipped — they are recreated by parent Function/Site migration.',
                'default' => 0,
                'example' => 5,
            ])
            ->addRule(Resource::TYPE_PROJECT_EMAIL_TEMPLATE, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of custom email templates to be migrated (one per templateId × locale pair).',
                'default' => 0,
                'example' => 7,
            ])
            ->addRule(Resource::TYPE_SITE, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of sites to be migrated.',
                'default' => 0,
                'example' => 5,
            ])
            ->addRule(Resource::TYPE_PROVIDER, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of providers to be migrated.',
                'default' => 0,
                'example' => 5,
            ])
            ->addRule(Resource::TYPE_TOPIC, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of topics to be migrated.',
                'default' => 0,
                'example' => 10,
            ])
            ->addRule(Resource::TYPE_SUBSCRIBER, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of subscribers to be migrated.',
                'default' => 0,
                'example' => 100,
            ])
            ->addRule(Resource::TYPE_MESSAGE, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of messages to be migrated.',
                'default' => 0,
                'example' => 50,
            ])
            ->addRule('size', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Size of files to be migrated in mb.',
                'default' => 0,
                'example' => 30000,
            ])
            ->addRule('version', [
                'type' => self::TYPE_STRING,
                'description' => 'Version of the Appwrite instance to be migrated.',
                'default' => '',
                'example' => '1.4.0',
            ]);

        $this->addRule(Resource::TYPE_OAUTH2_PROVIDER, [
            'type' => self::TYPE_INTEGER,
            'description' => 'Number of OAuth2 provider configurations to be migrated. Secrets (clientSecret, p8File) are never migrated — destination admin must re-enter them per provider.',
            'default' => 0,
            'example' => 5,
        ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Migration Report';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_MIGRATION_REPORT;
    }
}
