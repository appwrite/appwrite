<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Installation extends Model
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
            ->addRule('provider', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) provider name.',
                'default' => [],
                'example' => 'github',
                'array' => false,
            ])
            ->addRule('organization', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) organization name.',
                'default' => [],
                'example' => 'appwrite',
                'array' => false,
            ])
            ->addRule('providerInstallationId', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) installation ID.',
                'default' => '',
                'example' => '5322',
            ])
            ->addRule('fresh', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the installation still holds an access token. When false, it must be reconnected before its repositories can be reached.',
                'default' => true,
                'example' => true,
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Installation';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_INSTALLATION;
    }
}
