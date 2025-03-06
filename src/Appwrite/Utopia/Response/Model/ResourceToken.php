<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ResourceToken extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Token ID.',
                'default' => '',
                'example' => 'bb8ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Token creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('resourceId', [
                'type' => self::TYPE_STRING,
                'description' => 'Resource ID.',
                'default' => '',
                'example' => '5e5ea5c168bb8:5e5ea5c168bb8',
            ])
            ->addRule('resourceInternalId', [
                'type' => self::TYPE_STRING,
                'description' => 'File ID.',
                'default' => '',
                'example' => '1:1',
            ])
            ->addRule('resourceType', [
                'type' => self::TYPE_STRING,
                'description' => 'Resource type.',
                'default' => '',
                'example' => 'file',
            ])
            ->addRule('expire', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Token expiration date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
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
        return 'ResourceToken';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_RESOURCE_TOKEN;
    }
}
