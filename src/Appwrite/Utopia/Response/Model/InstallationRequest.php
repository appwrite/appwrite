<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class InstallationRequest extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Installation request ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Installation request creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Installation request update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('provider', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) provider name.',
                'default' => '',
                'example' => 'github',
            ])
            ->addRule('requester', [
                'type' => self::TYPE_STRING,
                'description' => 'Provider account that requested the installation.',
                'default' => '',
                'example' => 'octocat',
            ])
            ->addRule('organization', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) organization name. Empty until the request is approved on the provider.',
                'default' => '',
                'example' => 'appwrite',
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Request status. Either `requested` or, once approved on the provider, `ready`.',
                'default' => '',
                'example' => 'ready',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'InstallationRequest';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_INSTALLATION_REQUEST;
    }
}
