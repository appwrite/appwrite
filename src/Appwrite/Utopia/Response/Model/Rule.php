<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Rule extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Rule ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Rule creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Rule update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('domain', [
                'type' => self::TYPE_STRING,
                'description' => 'Domain name.',
                'default' => '',
                'example' => 'appwrite.company.com',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Action definition for the rule. Possible values are "api", "deployment", or "redirect"',
                'default' => '',
                'example' => 'deployment',
            ])
            ->addRule('trigger', [
                'type' => self::TYPE_STRING,
                'description' => 'Defines how the rule was created. Possible values are "manual" or "deployment"',
                'default' => '',
                'example' => 'manual',
            ])
            ->addRule('redirectUrl', [
                'type' => self::TYPE_STRING,
                'description' => 'URL to redirect to. Used if type is "redirect"',
                'default' => '',
                'example' => 'https://appwrite.io/docs',
            ])
            ->addRule('redirectStatusCode', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Status code to apply during redirect. Used if type is "redirect"',
                'default' => 301,
                'example' => 301,
            ])
            ->addRule('deploymentId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of deployment. Used if type is "deployment"',
                'default' => '',
                'example' => 'n3u9feiwmf',
            ])
            ->addRule('deploymentResourceType', [
                'type' => self::TYPE_ENUM,
                'description' => 'Type of deployment. Possible values are "function", "site". Used if rule\'s type is "deployment".',
                'default' => '',
                'example' => 'function',
                'enum' => ['function', 'site'],
            ])
            ->addRule('deploymentResourceId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID deployment\'s resource. Used if type is "deployment"',
                'default' => '',
                'example' => 'n3u9feiwmf',
            ])
            ->addRule('deploymentVcsProviderBranch', [
                'type' => self::TYPE_STRING,
                'description' => 'Name of Git branch that updates rule. Used if type is "deployment"',
                'default' => '',
                'example' => 'main',
            ])
            ->addRule('status', [
                'type' => self::TYPE_ENUM,
                'description' => 'Domain verification status. Possible values are "created", "verifying", "verified" and "unverified"',
                'default' => 'created',
                'example' => 'verified',
                'enum' => ['created', 'verifying', 'verified', 'unverified'],
            ])
            ->addRule('logs', [
                'type' => self::TYPE_STRING,
                'description' => 'Logs from rule verification or certificate generation. Certificate generation logs are prioritized if both are available.',
                'default' => '',
                'example' => 'Verification of DNS records failed with DNS resolver 8.8.8.8. Domain stage.myapp.com does not have DNS record.',
            ])
            ->addRule('renewAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Certificate auto-renewal date in ISO 8601 format.',
                'default' => APP_DATABASE_ATTRIBUTE_DATETIME,
                'example' => APP_DATABASE_ATTRIBUTE_DATETIME,
                'array' => false,
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
        return 'Rule';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PROXY_RULE;
    }
}
