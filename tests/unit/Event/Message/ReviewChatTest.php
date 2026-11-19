<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Message;

use Appwrite\Event\Message\ReviewChat;
use PHPUnit\Framework\TestCase;

final class ReviewChatTest extends TestCase
{
    private const array REPOSITORY = [
        'projectId' => 'project1',
        'projectInternalId' => '17',
        'repositoryId' => 'repository1',
        'installationId' => 'installation1',
        'providerInstallationId' => '42954928',
        'providerRepositoryId' => '943139433',
        'owner' => 'appwrite-test',
        'name' => 'svelte-starter',
    ];

    /**
     * The review service parses this payload with its own schema (appwrite/hansi,
     * packages/appwrite/src/payload.ts), so the shape is a contract between two codebases.
     */
    public function testPayloadIsTheReviewServiceJob(): void
    {
        $message = new ReviewChat(
            repository: self::REPOSITORY,
            pullNumber: 42,
            comment: [
                'id' => 1001,
                'kind' => 'review',
                'rootCommentId' => 900,
                'author' => 'octocat',
                'authorAssociation' => 'MEMBER',
                'url' => 'https://github.com/appwrite-test/svelte-starter/pull/42#discussion_r1001',
            ],
            unmentioned: true,
        );

        $this->assertSame([
            'type' => 'chat',
            'repository' => self::REPOSITORY,
            'pullNumber' => 42,
            'comment' => [
                'id' => 1001,
                'kind' => 'review',
                'rootCommentId' => 900,
                'author' => 'octocat',
                'authorAssociation' => 'MEMBER',
                'url' => 'https://github.com/appwrite-test/svelte-starter/pull/42#discussion_r1001',
            ],
            'unmentioned' => true,
        ], $message->toArray());
    }

    public function testMentionLeavesOutUnmentioned(): void
    {
        $message = new ReviewChat(
            repository: self::REPOSITORY,
            pullNumber: 42,
            comment: [
                'id' => 1001,
                'kind' => 'issue',
                'author' => 'octocat',
                'authorAssociation' => 'OWNER',
                'url' => 'https://github.com/appwrite-test/svelte-starter/pull/42#issuecomment-1001',
            ],
        );

        $this->assertArrayNotHasKey('unmentioned', $message->toArray());
        $this->assertSame($message->toArray(), ReviewChat::fromArray($message->toArray())->toArray());
    }
}
