<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Network\Platform as NetworkPlatform;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;

class PlatformApp extends PlatformBase
{
    /**
     * @return array<string>
     */
    protected function getSupportedTypes(): array
    {
        return [
            NetworkPlatform::TYPE_FLUTTER_IOS,
            NetworkPlatform::TYPE_FLUTTER_ANDROID,
            NetworkPlatform::TYPE_FLUTTER_LINUX,
            NetworkPlatform::TYPE_FLUTTER_MACOS,
            NetworkPlatform::TYPE_FLUTTER_WINDOWS,
            NetworkPlatform::TYPE_APPLE_IOS,
            NetworkPlatform::TYPE_APPLE_MACOS,
            NetworkPlatform::TYPE_APPLE_WATCHOS,
            NetworkPlatform::TYPE_APPLE_TVOS,
            NetworkPlatform::TYPE_ANDROID,
            NetworkPlatform::TYPE_UNITY,
            NetworkPlatform::TYPE_REACT_NATIVE_IOS,
            NetworkPlatform::TYPE_REACT_NATIVE_ANDROID,
        ];
    }

    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('type', [
                'type' => self::TYPE_ENUM,
                'description' => 'Platform type. Possible values are: ' . implode(', ', $this->getSupportedTypes()) . '.',
                'default' => '',
                'example' => NetworkPlatform::TYPE_APPLE_IOS,
                'enum' => [$this->getSupportedTypes()],
            ])
            ->addRule('identifier', [
                'type' => self::TYPE_STRING,
                'description' => 'Platform app identifier. iOS bundle ID or Android package name.  Empty string for other platforms.',
                'default' => '',
                'example' => 'com.company.appname',
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
        return 'Platform App';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PLATFORM_APP;
    }

    /**
     * Get Collection
     *
     * @return Document
     */
    public function filter(Document $document): Document
    {
        // DB level: 'key'
        // API level: 'identifier'
        $document->setAttribute('identifier', $document->getAttribute('key', null));
        $document->removeAttribute('key');

        // DB level attribute unused on API level
        $document->removeAttribute('store');

        return $document;
    }
}
