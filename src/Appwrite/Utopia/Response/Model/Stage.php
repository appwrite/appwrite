<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;

class Stage extends Model
{
    public function __construct()
    {
        $this
            ->addRule('id', [
                'type' => self::TYPE_STRING,
                'description' => 'Stage ID.',
                'default' => '',
                'example' => 'tablesDB.create',
            ])
            ->addRule('sdk', [
                'type' => self::TYPE_STRING,
                'description' => 'SDK method key (namespace.name) for this stage.',
                'default' => '',
                'example' => 'tablesDB.create',
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Stage status.',
                'default' => 'pending',
                'example' => 'completed',
            ])
            ->addRule('at', [
                'type' => self::TYPE_DATETIME,
                'description' => 'When the stage was completed or skipped, in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('actorType', [
                'type' => self::TYPE_STRING,
                'description' => 'Actor type when the stage was recorded.',
                'default' => '',
                'example' => 'user',
            ])
        ;
    }

    public function getName(): string
    {
        return 'Stage';
    }

    public function getType(): string
    {
        return Response::MODEL_STAGE;
    }

    public function filter(Document $document): Document
    {
        return $document;
    }
}
