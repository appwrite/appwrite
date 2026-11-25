<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ReviewFinding extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Finding ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Finding creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('path', [
                'type' => self::TYPE_STRING,
                'description' => 'Path of the file the finding is about.',
                'default' => '',
                'example' => 'src/index.ts',
            ])
            ->addRule('startLine', [
                'type' => self::TYPE_INTEGER,
                'description' => 'First line of the finding.',
                'default' => 0,
                'example' => 12,
            ])
            ->addRule('endLine', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Last line of the finding.',
                'default' => 0,
                'example' => 14,
            ])
            ->addRule('severity', [
                'type' => self::TYPE_STRING,
                'description' => 'Finding severity. Possible values: info, minor, major, critical.',
                'default' => '',
                'example' => 'major',
            ])
            ->addRule('category', [
                'type' => self::TYPE_STRING,
                'description' => 'Finding category, such as bug, security or performance.',
                'default' => '',
                'example' => 'bug',
            ])
            ->addRule('title', [
                'type' => self::TYPE_STRING,
                'description' => 'Finding title.',
                'default' => '',
                'example' => 'Missing null check',
            ])
            ->addRule('body', [
                'type' => self::TYPE_STRING,
                'description' => 'Finding explanation in Markdown.',
                'default' => '',
                'example' => '`user` can be null here.',
            ])
            ->addRule('suggestion', [
                'type' => self::TYPE_STRING,
                'description' => 'Replacement for the lines, offered as a suggestion.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Finding status. Possible values: posted (open on the pull request), dropped (not posted), resolved (fixed by a later commit), dismissed (settled in its thread).',
                'default' => 'posted',
                'example' => 'posted',
            ])
            ->addRule('dropReason', [
                'type' => self::TYPE_STRING,
                'description' => 'Why the finding was dropped, resolved or dismissed.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('providerCommentId', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) comment the finding was posted as.',
                'default' => '',
                'example' => '',
            ]);
    }

    public function getName(): string
    {
        return 'Review Finding';
    }

    public function getType(): string
    {
        return Response::MODEL_REVIEW_FINDING;
    }
}
