<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ReviewLearning extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Learning ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Learning creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('repositoryId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the repository whose reviews follow the learning.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('body', [
                'type' => self::TYPE_STRING,
                'description' => 'What the reviewer was asked to remember.',
                'default' => '',
                'example' => 'Don\'t flag missing error handling in scripts/.',
            ])
            ->addRule('author', [
                'type' => self::TYPE_STRING,
                'description' => 'GitHub login of who asked for it.',
                'default' => '',
                'example' => 'octocat',
            ])
            ->addRule('sourceUrl', [
                'type' => self::TYPE_STRING,
                'description' => 'The comment it was asked in, if known.',
                'default' => '',
                'example' => 'https://github.com/octocat/hello-world/pull/1#issuecomment-1',
            ]);
    }

    public function getName(): string
    {
        return 'Review Learning';
    }

    public function getType(): string
    {
        return Response::MODEL_REVIEW_LEARNING;
    }
}
