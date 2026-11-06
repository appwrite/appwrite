<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ReviewEvent extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Event ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Event creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Step of the review agent, such as tool_call or sandbox.failed.',
                'default' => '',
                'example' => 'tool_call',
            ])
            ->addRule('time', [
                'type' => self::TYPE_DATETIME,
                'description' => 'When the step happened, in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('data', [
                'type' => self::TYPE_JSON,
                'description' => 'Details of the step.',
                'default' => new \stdClass(),
                'example' => '{"tool": "read_file"}',
            ]);
    }

    public function getName(): string
    {
        return 'Review Event';
    }

    public function getType(): string
    {
        return Response::MODEL_REVIEW_EVENT;
    }
}
