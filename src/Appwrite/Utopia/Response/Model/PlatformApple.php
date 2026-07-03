<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Network\Platform;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;

class PlatformApple extends PlatformBase
{
    public function __construct()
    {
        $this->conditions = [
            'type' => Platform::TYPE_APPLE,
        ];

        parent::__construct();

        $this
            ->addRule('bundleIdentifier', [
                'type' => self::TYPE_STRING,
                'description' => 'Apple bundle identifier.',
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
        return 'Platform Apple';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PLATFORM_APPLE;
    }

    public function filter(Document $document): Document
    {
        // DB level: 'key'
        // API level: 'bundleIdentifier'
        $document->setAttribute('bundleIdentifier', $document->getAttribute('key', null));
        $document->removeAttribute('key');

        return $document;
    }
}
