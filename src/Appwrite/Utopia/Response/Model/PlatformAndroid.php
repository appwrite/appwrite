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
            ->addRule('fingerprints', [
                'type' => self::TYPE_STRING,
                'description' => 'SHA-256 fingerprints of the certificates the app is signed with. Passkeys created in the app are accepted when its signature matches one.',
                'required' => true,
                'default' => [],
                'example' => '14:6D:E9:83:C5:73:06:50:D8:EE:B9:95:2F:34:FC:64:16:A0:83:42:E6:1D:BE:A8:8A:04:96:B2:3F:CF:44:E5',
                'array' => true,
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
