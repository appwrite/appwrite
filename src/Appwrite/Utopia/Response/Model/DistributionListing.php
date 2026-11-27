<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Distribution\Platform;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;

class DistributionListing extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Listing ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Listing creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Listing update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('appId', [
                'type' => self::TYPE_STRING,
                'description' => 'App ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('platform', [
                'type' => self::TYPE_ENUM,
                'description' => 'Platform of the store listing.',
                'default' => Platform::Android->value,
                'example' => Platform::Android->value,
                'enum' => Platform::values(),
                'enumSDKName' => 'DistributionPlatform',
            ])
            ->addRule('locale', [
                'type' => self::TYPE_STRING,
                'description' => 'Listing locale.',
                'default' => '',
                'example' => 'en-US',
            ])
            ->addRule('fields', [
                'type' => self::TYPE_JSON,
                'description' => 'Listing text: name, subtitle or short description, description, keywords, promotional text, and support, marketing and privacy URLs.',
                'default' => [],
                'example' => ['name' => 'My App', 'description' => 'The best app.'],
            ])
            ->addRule('screenshots', [
                'type' => self::TYPE_JSON,
                'description' => 'Ordered screenshots, each with its ID, set, size, width, height, SHA-256 digest and, on Android, its AI-generated attestation.',
                'default' => [],
                'example' => [['$id' => '5e5ea5c16897e', 'set' => 'phoneScreenshots', 'size' => 204800, 'width' => 1080, 'height' => 1920, 'digest' => '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08', 'aiGeneratedState' => 'aiGeneratedStateNotAiGenerated']],
                'array' => true,
            ])
            ->addRule('revision', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Listing revision, incremented on every change.',
                'default' => 0,
                'example' => 3,
            ]);
    }

    public function getName(): string
    {
        return 'DistributionListing';
    }

    public function getType(): string
    {
        return Response::MODEL_DISTRIBUTION_LISTING;
    }

    public function filter(Document $document): Document
    {
        $screenshots = $document->getAttribute('screenshots');
        if (!\is_array($screenshots)) {
            return $document;
        }

        $document->setAttribute('screenshots', \array_map(
            static function (mixed $screenshot): mixed {
                if ($screenshot instanceof Document) {
                    $screenshot = $screenshot->getArrayCopy();
                }

                if (\is_array($screenshot)) {
                    unset($screenshot['path']);
                }

                return $screenshot;
            },
            $screenshots,
        ));

        return $document;
    }
}
