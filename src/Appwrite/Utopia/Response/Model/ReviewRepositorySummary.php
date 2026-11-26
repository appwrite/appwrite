<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ReviewRepositorySummary extends Model
{
    public function __construct()
    {
        $this
            ->addRule('repositoryId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the repository the reviews belong to.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('providerRepositoryOwner', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS repository owner, as of the latest review.',
                'default' => '',
                'example' => 'appwrite',
            ])
            ->addRule('providerRepositoryName', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS repository name, as of the latest review.',
                'default' => '',
                'example' => 'appwrite',
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
                'description' => 'Pull requests whose latest review approved.',
                'default' => 0,
                'example' => 35,
            ])
            ->addRule('approvedFirst', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Pull requests approved by their first review.',
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
            ->addRule('lastReviewAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Date of the latest review in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ]);
    }

    public function getName(): string
    {
        return 'Review Repository Summary';
    }

    public function getType(): string
    {
        return Response::MODEL_REVIEW_REPOSITORY_SUMMARY;
    }
}
