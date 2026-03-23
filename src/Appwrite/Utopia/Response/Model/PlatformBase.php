<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Network\Platform as NetworkPlatform;
use Appwrite\Utopia\Response\Model;

abstract class PlatformBase extends Model
{
    /**
     * @return array<string>
     */
    protected function getSupportedTypes(): array
    {
        return [
            NetworkPlatform::TYPE_WEB,
            NetworkPlatform::TYPE_FLUTTER_WEB,
            NetworkPlatform::TYPE_REACT_NATIVE_WEB,
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
        ;
    }
}
