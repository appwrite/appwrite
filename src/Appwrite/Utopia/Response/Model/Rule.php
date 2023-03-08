<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use stdClass;
use Utopia\Database\Document;

class Rule extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Function ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Function creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Function update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('domain', [
                'type' => self::TYPE_STRING,
                'description' => 'Domain name.',
                'default' => '',
                'example' => 'appwrite.company.com',
            ])
            ->addRule('resourceType', [
                'type' => self::TYPE_STRING,
                'description' => 'Action definition for the rule. Possible values are "api", "function", or "redirect"',
                'default' => '',
                'example' => 'function',
            ])
            ->addRule('resourceId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of resource for the action type. If resourceType is "api" or "url", leave empty. If resourceType is "function", provide ID of the function.',
                'default' => '',
                'example' => 'myAwesomeFunction',
            ])
            ->addRule('redirect', [
                'type' => self::TYPE_STRING,
                'description' => 'Redirect URL for redirect action. Only provide if resourceType is "redirect"',
                'default' => '',
                'example' => 'https://appwrite.io/',
            ])
            ->addRule('verification', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Domain verification status.',
                'default' => false,
                'example' => true,
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
