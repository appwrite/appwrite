<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Network\Platform;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;

class PlatformAndroid extends PlatformBase
{
    public function __construct()
    {
        $this->conditions = [
            'type' => Platform::TYPE_ANDROID,
        ];

        parent::__construct();

        $this
            ->addRule('applicationId', [
                'type' => self::TYPE_STRING,
                'description' => 'Android application ID.',
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
        return 'Platform Android';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PLATFORM_ANDROID;
    }

    public function filter(Document $document): Document
    {
        // DB level: 'key'
        // API level: 'applicationId'
        $document->setAttribute('applicationId', $document->getAttribute('key', null));
        $document->removeAttribute('key');

        return $document;
    }
}
