<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;

class MockNumber extends Model
{
    public function __construct()
    {
        $this
            ->addRule('number', [
                'type' => self::TYPE_STRING,
                'description' => 'Mock phone number for testing phone authentication. Useful for testing phone authentication without sending an SMS.',
                'default' => '',
                'example' => '+1612842323',
            ])
            ->addRule('otp', [
                'type' => self::TYPE_STRING,
                'description' => 'Mock OTP for the number. ',
                'default' => '',
                'example' => '123456',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Attribute creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Attribute update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ]);
        ;
    }

    public function filter(Document $document): Document
    {
        if ($document->isSet('phone')) {
            $document->setAttribute('number', $document->getAttribute('phone'));
            $document->removeAttribute('phone');
        }

        return $document;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Mock Number';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_MOCK_NUMBER;
    }
}
