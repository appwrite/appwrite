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
            ->addRule('redirectUrl', [
                'type' => self::TYPE_STRING,
                'description' => 'URL to redirect to. Used if type is "redirect"',
                'default' => '',
                'example' => 'https://appwrite.io/docs',
            ])
            ->addRule('redirectStatusCode', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Status code to apply during redirect. Used if type is "redirect"',
                'default' => '',
                'example' => 301,
            ])
            ->addRule('deploymentId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of deployment. Used if type is "deployment"',
                'default' => '',
                'example' => 'n3u9feiwmf',
            ])
            ->addRule('deploymentResourceType', [
                'type' => self::TYPE_STRING,
                'description' => 'Type of deployment. Possible values are "function", "site". Used if rule\'s type is "deployment".',
                'default' => '',
                'example' => 'function',
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
                'example' => 'function',
            ])
            ->addRule('deploymentUpdatePolicy', [
                'type' => self::TYPE_STRING,
                'description' => 'Describes when to update deployment ID of this rule. Can be "active" or "branch". Used if type is "deployment"',
                'default' => '',
                'example' => 'function',
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Domain verification status. Possible values are "created", "verifying", "verified" and "unverified"',
                'default' => false,
                'example' => 'verified',
            ])
            ->addRule('logs', [
                'type' => self::TYPE_STRING,
                'description' => 'Certificate generation logs. This will return an empty string if generation did not run, or succeeded.',
                'default' => '',
                'example' => 'HTTP challegne failed.',
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
