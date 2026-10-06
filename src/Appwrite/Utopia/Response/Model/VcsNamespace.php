<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class VcsNamespace extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) namespace ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) namespace display name.',
                'default' => '',
                'example' => 'Appwrite',
            ])
            ->addRule('path', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) namespace path, used to filter repositories by namespace.',
                'default' => '',
                'example' => 'appwrite',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Namespace type. Either the user\'s personal namespace or a group/organization.',
                'default' => '',
                'example' => 'user',
            ])
            ->addRule('avatarUrl', [
                'type' => self::TYPE_STRING,
                'description' => 'Namespace avatar URL.',
                'default' => '',
                'example' => 'https://example.com/avatar.png',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'VcsNamespace';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_VCS_NAMESPACE;
    }
}
