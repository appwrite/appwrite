<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Network\Platform;
use Appwrite\Utopia\Response;

class PlatformWeb extends PlatformBase
{
    public function __construct()
    {
        $this->conditions = [
            'type' => [
                Platform::TYPE_WEB,
                // Backwards compatibility
                'flutter-web',
                'unity',
                'flutter-macos',
                'flutter-ios',
                'react-native-ios',
                'apple-ios',
                'apple-macos',
                'apple-watchos',
                'apple-tvos',
                'flutter-android',
                'react-native-android',
                'flutter-windows',
                'flutter-linux',
            ],
        ];

        parent::__construct();

        $this
            ->addRule('hostname', [
                'type' => self::TYPE_STRING,
                'description' => 'Web app hostname. Empty string for other platforms.',
                'default' => '',
                'example' => 'app.example.com',
            ])
            // Backwards compatibility
            ->addRule('key', [
                'hidden' => true,
                'type' => self::TYPE_STRING,
                'description' => 'Deprecated for old versions using alias endpoint to create universal platform.',
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
        return 'Platform Web';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PLATFORM_WEB;
    }
}
