<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Network\Platform;
use Appwrite\Utopia\Response\Model;

abstract class PlatformBase extends Model
{
    public function getSupportedTypes(): array
    {
        return [
            Platform::TYPE_WINDOWS,
            Platform::TYPE_APPLE,
            Platform::TYPE_ANDROID,
            Platform::TYPE_LINUX,
            Platform::TYPE_WEB,
        ];
    }

    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Platform ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Platform creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Platform update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Platform name.',
                'default' => '',
                'example' => 'My Web App',
            ])
            ->addRule('type', [
                'type' => self::TYPE_ENUM,
                'description' => 'Platform type. Possible values are: ' . implode(', ', self::getSupportedTypes()) . '.',
                'default' => '',
                'example' => Platform::TYPE_WEB,
                'enum' => self::getSupportedTypes(),
            ])
        ;
    }
}
