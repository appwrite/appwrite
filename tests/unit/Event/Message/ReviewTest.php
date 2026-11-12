<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Message;

use Appwrite\Event\Message\Review;
use PHPUnit\Framework\TestCase;

final class ReviewTest extends TestCase
{
    /**
     * The review service parses this payload with its own schema (appwrite/hansi,
     * packages/appwrite/src/payload.ts), so the shape is a contract between two codebases.
     */
    public function testPayloadIsTheReviewServiceJob(): void
    {
        $message = new Review(
            reviewId: 'review1',
            repository: [
                'projectId' => 'project1',
                'projectInternalId' => '17',
                'repositoryId' => 'repository1',
                'installationId' => 'installation1',
                'providerInstallationId' => '42954928',
                'providerRepositoryId' => '943139433',
                'owner' => 'appwrite-test',
                'name' => 'svelte-starter',
            ],
            pullNumber: 42,
            headSha: \str_repeat('a', 40),
            trigger: ['kind' => 'opened'],
        );

        $this->assertSame([
            'type' => 'review',
            'reviewId' => 'review1',
            'repository' => [
                'projectId' => 'project1',
                'projectInternalId' => '17',
                'repositoryId' => 'repository1',
                'installationId' => 'installation1',
                'providerInstallationId' => '42954928',
                'providerRepositoryId' => '943139433',
                'owner' => 'appwrite-test',
                'name' => 'svelte-starter',
            ],
            'pullNumber' => 42,
            'headSha' => \str_repeat('a', 40),
            'trigger' => ['kind' => 'opened'],
        ], $message->toArray());
    }

    public function testPayloadSurvivesTheQueue(): void
    {
        $message = new Review(
            reviewId: 'review1',
            repository: [
                'projectId' => 'project1',
                'projectInternalId' => '17',
                'repositoryId' => 'repository1',
                'installationId' => 'installation1',
                'providerInstallationId' => '42954928',
                'providerRepositoryId' => '943139433',
                'owner' => 'appwrite-test',
                'name' => 'svelte-starter',
            ],
            pullNumber: 42,
            headSha: \str_repeat('a', 40),
            trigger: ['kind' => 'mention', 'comment' => ['id' => 7, 'kind' => 'issue']],
        );

        $this->assertSame($message->toArray(), Review::fromArray($message->toArray())->toArray());
    }
}
