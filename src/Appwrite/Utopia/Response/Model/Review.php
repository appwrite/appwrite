<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Review extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Review ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Date the review was queued, in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Review update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('repositoryId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the repository the review belongs to.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('providerRepositoryId', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) repository ID.',
                'default' => '',
                'example' => '583127841',
            ])
            ->addRule('providerRepositoryOwner', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) repository owner.',
                'default' => '',
                'example' => 'appwrite',
            ])
            ->addRule('providerRepositoryName', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) repository name.',
                'default' => '',
                'example' => 'appwrite',
            ])
            ->addRule('providerPullRequestId', [
                'type' => self::TYPE_STRING,
                'description' => 'Pull request number.',
                'default' => '',
                'example' => '42',
            ])
            ->addRule('providerCommitHash', [
                'type' => self::TYPE_STRING,
                'description' => 'Head commit of the pull request: the one the review was queued for, then the one it reviews.',
                'default' => '',
                'example' => '7c3f25d',
            ])
            ->addRule('providerBaseCommitHash', [
                'type' => self::TYPE_STRING,
                'description' => 'Base commit the head is compared with, once the review has started.',
                'default' => '',
                'example' => 'b1e7d2a',
            ])
            ->addRule('trigger', [
                'type' => self::TYPE_STRING,
                'description' => 'What queued the review. Possible values: opened, synchronize, ready_for_review, retry, mention.',
                'default' => '',
                'example' => 'opened',
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Review status. Possible values: queued, processing, completed, skipped, failed.',
                'default' => '',
                'example' => 'completed',
            ])
            ->addRule('verdict', [
                'type' => self::TYPE_STRING,
                'description' => 'Review verdict once completed. Possible values: approve, request_changes, comment.',
                'default' => '',
                'example' => 'comment',
            ])
            ->addRule('tier', [
                'type' => self::TYPE_STRING,
                'description' => 'Merge confidence once completed, from S (best) to F.',
                'default' => '',
                'example' => 'A',
            ])
            ->addRule('summary', [
                'type' => self::TYPE_STRING,
                'description' => 'Review summary in Markdown.',
                'default' => '',
                'example' => 'Looks good overall; one missing null check.',
            ])
            ->addRule('reason', [
                'type' => self::TYPE_STRING,
                'description' => 'Why the review was skipped or failed.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('providerReviewId', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) review ID, once posted.',
                'default' => '',
                'example' => '1834620151',
            ])
            ->addRule('startedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Date the review started, in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('completedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Date the review ended, in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('findings', [
                'type' => Response::MODEL_REVIEW_FINDING,
                'description' => 'Findings posted with the review.',
                'default' => [],
                'example' => [],
                'array' => true,
            ]);
    }

    public function getName(): string
    {
        return 'Review';
    }

    public function getType(): string
    {
        return Response::MODEL_REVIEW;
    }
}
