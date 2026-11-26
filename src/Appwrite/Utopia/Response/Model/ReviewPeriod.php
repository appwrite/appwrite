<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ReviewPeriod extends Model
{
    public function __construct()
    {
        $this
            ->addRule('start', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Start of the period in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('end', [
                'type' => self::TYPE_DATETIME,
                'description' => 'End of the period in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('pulls', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Pull requests reviewed.',
                'default' => 0,
                'example' => 42,
            ])
            ->addRule('reviews', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Completed reviews of the pull requests.',
                'default' => 0,
                'example' => 61,
            ])
            ->addRule('approved', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Pull requests whose latest review of the period approved.',
                'default' => 0,
                'example' => 35,
            ])
            ->addRule('approvedFirst', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Pull requests approved by their first review of the period.',
                'default' => 0,
                'example' => 24,
            ])
            ->addRule('findings', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Findings posted on the pull requests.',
                'default' => 0,
                'example' => 58,
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
                'example' => 7,
            ])
            ->addRule('minor', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Minor findings posted.',
                'default' => 0,
                'example' => 32,
            ])
            ->addRule('info', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Informational findings posted.',
                'default' => 0,
                'example' => 18,
            ])
            ->addRule('resolved', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Findings fixed by a later commit.',
                'default' => 0,
                'example' => 41,
            ])
            ->addRule('dismissed', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Findings settled in their thread or dismissed by a maintainer.',
                'default' => 0,
                'example' => 6,
            ])
            ->addRule('duration', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Median time from a review being queued to it completing, in milliseconds. 0 when no review completed.',
                'default' => 0,
                'example' => 134000,
            ]);
    }

    public function getName(): string
    {
        return 'Review Period';
    }

    public function getType(): string
    {
        return Response::MODEL_REVIEW_PERIOD;
    }
}
