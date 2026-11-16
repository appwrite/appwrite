<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Reviews\Strength;
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
            ->addRule('organizationUrl', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) organization URL. Points at the configured instance, which is not the provider\'s own host for a self-hosted install.',
                'default' => '',
                'example' => 'https://github.com/appwrite',
            ])
            ->addRule('reviewNewRepositories', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether code review is turned on for repositories added to the installation later.',
                'default' => false,
                'example' => false,
            ])
            ->addRule('reviewStrength', [
                'type' => self::TYPE_ENUM,
                'description' => 'How hard pull requests of repositories added to the installation later are reviewed. Possible values: basic, normal, expert.',
                'default' => Strength::Normal->value,
                'example' => Strength::Normal->value,
                'enum' => Strength::values(),
                'enumSDKName' => 'ReviewStrength',
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
