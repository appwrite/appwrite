<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Network\Platform as NetworkPlatform;
use Appwrite\Utopia\Response;

class PlatformWeb extends PlatformBase
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
        parent::__construct();

        $this
            ->addRule('type', [
                'type' => self::TYPE_ENUM,
                'description' => 'Platform type. Possible values are: ' . implode(', ', $this->getSupportedTypes()) . '.',
                'default' => '',
                'example' => NetworkPlatform::TYPE_WEB,
                'enum' => $this->getSupportedTypes(),
            ])
            ->addRule('hostname', [
                'type' => self::TYPE_STRING,
                'description' => 'Web app hostname. Empty string for other platforms.',
                'default' => '',
                'example' => 'app.example.com',
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
