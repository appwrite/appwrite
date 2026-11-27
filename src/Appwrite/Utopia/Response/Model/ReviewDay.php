<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ReviewDay extends Model
{
    public function __construct()
    {
        $this
            ->addRule('date', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Start of the day, in UTC, in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('reviews', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Reviews completed that day.',
                'default' => 0,
                'example' => 6,
            ])
            ->addRule('approved', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Reviews that approved.',
                'default' => 0,
                'example' => 4,
            ])
            ->addRule('changesRequested', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Reviews that requested changes.',
                'default' => 0,
                'example' => 1,
            ])
            ->addRule('commented', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Reviews that only commented.',
                'default' => 0,
                'example' => 1,
            ])
            ->addRule('findings', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Findings the reviews posted.',
                'default' => 0,
                'example' => 9,
            ])
            ->addRule('critical', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Critical findings posted.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('major', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Major findings posted.',
                'default' => 0,
                'example' => 2,
            ])
            ->addRule('minor', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Minor findings posted.',
                'default' => 0,
                'example' => 5,
            ])
            ->addRule('info', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Informational findings posted.',
                'default' => 0,
                'example' => 2,
            ])
            ->addRule('resolved', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Findings since fixed by a later commit.',
                'default' => 0,
                'example' => 6,
            ])
            ->addRule('dismissed', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Findings since settled in their thread or dismissed by a maintainer.',
                'default' => 0,
                'example' => 1,
            ]);
    }

    public function getName(): string
    {
        return 'Review Day';
    }

    public function getType(): string
    {
        return Response::MODEL_REVIEW_DAY;
    }
}
