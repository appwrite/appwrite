<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Network\Platform;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;

class PlatformLinux extends PlatformBase
{
    public function __construct()
    {
        $this->conditions = [
            'type' => Platform::TYPE_LINUX,
        ];

        parent::__construct();

        $this
            ->addRule('packageName', [
                'type' => self::TYPE_STRING,
                'description' => 'Linux package name.',
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
        return 'Platform Linux';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PLATFORM_LINUX;
    }

    public function filter(Document $document): Document
    {
        // DB level: 'key'
        // API level: 'packageName'
        $document->setAttribute('packageName', $document->getAttribute('key', null));
        $document->removeAttribute('key');

        return $document;
    }
}
