<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Network\Platform;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;

class PlatformWindows extends PlatformBase
{
    public function __construct()
    {
        $this->conditions = [
            'type' => Platform::TYPE_WINDOWS,
        ];

        parent::__construct();

        $this
            ->addRule('packageIdentifierName', [
                'type' => self::TYPE_STRING,
                'description' => 'Windows package identifier name.',
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
        return 'Platform Windows';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PLATFORM_WINDOWS;
    }

    public function filter(Document $document): Document
    {
        // DB level: 'key'
        // API level: 'packageIdentifierName'
        $document->setAttribute('packageIdentifierName', $document->getAttribute('key', null));
        $document->removeAttribute('key');

        return $document;
    }
}
