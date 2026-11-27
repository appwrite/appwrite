<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Distribution\Build\Stage;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;

class DistributionLog extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Log ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Log creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('buildId', [
                'type' => self::TYPE_STRING,
                'description' => 'Build ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('sequence', [
                'type' => self::TYPE_STRING,
                'description' => 'Emission time of the source event in nanoseconds, as a string because it exceeds 2^53.',
                'default' => '',
                'example' => '1739871234567890123',
            ])
            ->addRule('chunk', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Index of this chunk within its event.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('stage', [
                'type' => self::TYPE_STRING,
                'description' => 'Stage that wrote the log. Empty or one of `' . \implode('`, `', Stage::values()) . '`.',
                'default' => '',
                'example' => Stage::Build->value,
            ])
            ->addRule('content', [
                'type' => self::TYPE_STRING,
                'description' => 'Log content, with secrets masked.',
                'default' => '',
                'example' => 'Running Gradle task \'bundleRelease\'...',
            ]);
    }

    public function getName(): string
    {
        return 'DistributionLog';
    }

    public function getType(): string
    {
        return Response::MODEL_DISTRIBUTION_LOG;
    }

    public function filter(Document $document): Document
    {
        $sequence = $document->getAttribute('sequence');

        if ($sequence !== null) {
            $document->setAttribute('sequence', (string) $sequence);
        }

        return $document;
    }
}
