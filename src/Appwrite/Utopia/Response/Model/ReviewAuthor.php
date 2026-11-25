<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ReviewAuthor extends Model
{
    public function __construct()
    {
        $this
            ->addRule('author', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS login of the pull requests\' author. Empty for pull requests reviewed before authors were recorded.',
                'default' => '',
                'example' => 'octocat',
            ])
            ->addRule('pulls', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Pull requests reviewed.',
                'default' => 0,
                'example' => 12,
            ])
            ->addRule('reviews', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Completed reviews of the pull requests.',
                'default' => 0,
                'example' => 20,
            ])
            ->addRule('approved', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Pull requests whose latest review approved.',
                'default' => 0,
                'example' => 9,
            ])
            ->addRule('approvedFirst', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Pull requests approved by their first review.',
                'default' => 0,
                'example' => 5,
            ])
            ->addRule('findings', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Findings posted on the pull requests.',
                'default' => 0,
                'example' => 31,
            ])
            ->addRule('critical', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Critical findings posted.',
                'default' => 0,
                'example' => 1,
            ])
            ->addRule('major', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Major findings posted.',
                'default' => 0,
                'example' => 6,
            ])
            ->addRule('resolved', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Findings fixed by a later commit.',
                'default' => 0,
                'example' => 22,
            ])
            ->addRule('dismissed', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Findings settled in their thread or dismissed by a maintainer.',
                'default' => 0,
                'example' => 4,
            ])
            ->addRule('tier', [
                'type' => self::TYPE_STRING,
                'description' => 'Average merge confidence tier of the pull requests\' latest reviews: S, A, B, C, D or F. Empty when none has one.',
                'default' => '',
                'example' => 'A',
            ])
            ->addRule('lastReviewAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Date of the latest review in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ]);
    }

    public function getName(): string
    {
        return 'Review Author';
    }

    public function getType(): string
    {
        return Response::MODEL_REVIEW_AUTHOR;
    }
}
