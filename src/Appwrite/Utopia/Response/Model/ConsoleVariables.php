<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ConsoleVariables extends Model
{
    public function __construct()
    {
        $this
            ->addRule('_APP_DOMAIN_TARGET_CNAME', [
                'type' => self::TYPE_STRING,
                'description' => 'CNAME target for your Appwrite custom domains.',
                'default' => '',
                'example' => 'appwrite.io',
            ])
            ->addRule('_APP_DOMAIN_TARGET_A', [
                'type' => self::TYPE_STRING,
                'description' => 'A target for your Appwrite custom domains.',
                'default' => '',
                'example' => '127.0.0.1',
            ])
            ->addRule('_APP_COMPUTE_BUILD_TIMEOUT', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Maximum build timeout in seconds.',
                'default' => '',
                'example' => 900,
            ])
            ->addRule('_APP_DOMAIN_TARGET_AAAA', [
                'type' => self::TYPE_STRING,
                'description' => 'AAAA target for your Appwrite custom domains.',
                'default' => '',
                'example' => '::1',
            ])
            ->addRule('_APP_DOMAIN_TARGET_CAA', [
                'type' => self::TYPE_STRING,
                'description' => 'CAA target for your Appwrite custom domains.',
                'default' => '',
                'example' => 'digicert.com',
            ])
            ->addRule('_APP_STORAGE_LIMIT', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Maximum file size allowed for file upload in bytes.',
                'default' => '',
                'example' => '30000000',
            ])
            ->addRule('_APP_COMPUTE_SIZE_LIMIT', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Maximum file size allowed for deployment in bytes.',
                'default' => '',
                'example' => '30000000',
            ])
            ->addRule('_APP_USAGE_STATS', [
                'type' => self::TYPE_STRING,
                'description' => 'Defines if usage stats are enabled. This value is set to \'enabled\' by default, to disable the usage stats set the value to \'disabled\'.',
                'default' => '',
                'example' => 'enabled',
            ])
            ->addRule('_APP_VCS_ENABLED', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Defines if VCS (Version Control System) is enabled.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('_APP_DOMAIN_ENABLED', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Defines if main domain is configured. If so, custom domains can be created.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('_APP_ASSISTANT_ENABLED', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Defines if AI assistant is enabled.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('_APP_DOMAIN_SITES', [
                'type' => self::TYPE_STRING,
                'description' => 'A domain to use for site URLs.',
                'default' => '',
                'example' => 'sites.localhost',
            ])
            ->addRule('_APP_DOMAIN_FUNCTIONS', [
                'type' => self::TYPE_STRING,
                'description' => 'A domain to use for function URLs.',
                'default' => '',
                'example' => 'functions.localhost',
            ])
            ->addRule(
                '_APP_OPTIONS_FORCE_HTTPS',
                [
                    'type' => self::TYPE_STRING,
                    'description' => 'Defines if HTTPS is enforced for all requests.',
                    'default' => '',
                    'example' => 'enabled',
                ]
            )
            ->addRule(
                '_APP_DOMAINS_NAMESERVERS',
                [
                    'type' => self::TYPE_STRING,
                    'description' => 'Comma-separated list of nameservers.',
                    'default' => '',
                    'example' => 'ns1.example.com,ns2.example.com',
                ]
            );
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Console Variables';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_CONSOLE_VARIABLES;
    }
}
