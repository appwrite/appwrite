<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Videos;

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Platform\Modules\Videos\Http\Videos\Delete;
use Appwrite\Platform\Modules\Videos\Http\Videos\Renditions\Delete as DeleteRendition;
use Appwrite\Platform\Modules\Videos\Http\Videos\Update;
use Utopia\Query\NativeQuery;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;

/**
 * Update, delete-video and delete-rendition must each invoke the injected
 * purge with the project and video ids. A rename used to skip the call when
 * read("any") was unchanged; every successful update must purge now.
 */
final class PurgeVideoSegmentCacheTest extends TestCase
{
    /**
     * @return \Iterator<string, array{0: array<int, string>, 1: string, 2: ?array<int, string>}>
     */
    public static function updateCases(): \Iterator
    {
        $any = [(string) Permission::read(Role::any())];
        $user = [(string) Permission::read(Role::user('user1'))];

        yield 'rename keeps public read' => [$any, 'renamed', null];
        yield 'restrict public read' => [$any, 'still-named', $user];
        yield 'grant public read' => [$user, 'opened', $any];
    }

    /**
     * @param array<int, string> $startingPermissions
     * @param ?array<int, string> $permissions
     */
    #[DataProvider('updateCases')]
    public function testUpdateAlwaysPurges(
        array $startingPermissions,
        string $name,
        ?array $permissions
    ): void {
        $video = $this->video($startingPermissions);
        $updated = clone $video;
        $updated->setAttribute('name', $name);
        if ($permissions !== null) {
            $updated->setAttribute('$permissions', $permissions);
        }

        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('getDocument')
            ->with('videos', 'vid1')
            ->willReturn($video);
        $db->expects($this->once())
            ->method('updateDocument')
            ->with('videos', 'vid1', $this->isInstanceOf(Document::class))
            ->willReturn($updated);

        $seen = [];
        // Skip Action::__construct — it builds an SDK Method that needs Swoole.
        $action = new class () extends Update {
            public function __construct()
            {
            }
        };
        $action->action(
            'vid1',
            $name,
            $permissions,
            $this->okResponse($updated),
            $db,
            new User(),
            $this->apiKeyAuthorization(),
            new Document(['$id' => 'proj1']),
            $this->events(),
            static function (string $projectId, string $videoId) use (&$seen): void {
                $seen[] = [$projectId, $videoId];
            },
        );

        $this->assertSame([['proj1', 'vid1']], $seen);
    }

    public function testDeleteVideoPurges(): void
    {
        if (!\class_exists(NativeQuery::class)) {
            $this->markTestSkipped('Requires utopia extension NativeQuery (Query::equal on delete).');
        }

        $video = $this->video();

        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('getDocument')
            ->with('videos', 'vid1')
            ->willReturn($video);
        $db->expects($this->once())
            ->method('count')
            ->willReturn(0);
        $db->expects($this->once())
            ->method('deleteDocument')
            ->with('videos', 'vid1')
            ->willReturn(true);

        $publisher = $this->createMock(DeletePublisher::class);
        $publisher->expects($this->once())->method('enqueue');

        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('output')
            ->with($video, Response::MODEL_VIDEO)
            ->willReturn(['$id' => 'vid1']);
        $response->expects($this->once())->method('noContent');

        $seen = [];
        $action = new class () extends Delete {
            public function __construct()
            {
            }
        };
        $action->action(
            'vid1',
            $response,
            $db,
            new User(),
            $this->apiKeyAuthorization(),
            new Document(['$id' => 'proj1']),
            $this->events(withPayload: true),
            $publisher,
            static function (string $projectId, string $videoId) use (&$seen): void {
                $seen[] = [$projectId, $videoId];
            },
        );

        $this->assertSame([['proj1', 'vid1']], $seen);
    }

    public function testDeleteRenditionPurges(): void
    {
        $video = $this->video();
        $rendition = new Document([
            '$id' => 'ren1',
            'videoInternalId' => $video->getSequence(),
            'status' => 'ready',
            'output' => 'hls',
        ]);

        $db = $this->createMock(Database::class);
        $db->expects($this->exactly(2))
            ->method('getDocument')
            ->willReturnCallback(static fn (string $collection, string $id): Document => match ([$collection, $id]) {
                ['videos', 'vid1'] => $video,
                ['videosRenditions', 'ren1'] => $rendition,
                default => new Document(),
            });
        $db->expects($this->once())
            ->method('deleteDocument')
            ->with('videosRenditions', 'ren1')
            ->willReturn(true);

        $publisher = $this->createMock(DeletePublisher::class);
        $publisher->expects($this->once())->method('enqueue');

        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('output')
            ->with($rendition, Response::MODEL_VIDEO_RENDITION)
            ->willReturn(['$id' => 'ren1']);
        $response->expects($this->once())->method('noContent');

        $seen = [];
        $action = new class () extends DeleteRendition {
            public function __construct()
            {
            }
        };
        $action->action(
            'vid1',
            'ren1',
            $response,
            $db,
            new User(),
            $this->apiKeyAuthorization(),
            new Document(['$id' => 'proj1']),
            $this->events(withPayload: true),
            $publisher,
            static function (string $projectId, string $videoId) use (&$seen): void {
                $seen[] = [$projectId, $videoId];
            },
        );

        $this->assertSame([['proj1', 'vid1']], $seen);
    }

    /**
     * @param array<int, string> $permissions
     */
    private function video(array $permissions = []): Document
    {
        if ($permissions === []) {
            $permissions = [(string) Permission::read(Role::any())];
        }

        return new Document([
            '$id' => 'vid1',
            '$sequence' => '7',
            '$permissions' => $permissions,
            '$updatedAt' => '2026-01-01T00:00:00.000+00:00',
            'fileId' => 'file1',
            'name' => 'original',
            'timelineStatus' => 'ready',
        ]);
    }

    private function apiKeyAuthorization(): Authorization
    {
        $authorization = $this->createStub(Authorization::class);
        $authorization->method('getRoles')->willReturn([User::ROLE_KEYS]);
        $authorization->method('skip')->willReturnCallback(
            static fn (callable $callback): mixed => $callback()
        );

        return $authorization;
    }

    private function okResponse(Document $video): Response
    {
        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('dynamic')
            ->with($video, Response::MODEL_VIDEO);

        return $response;
    }

    private function events(bool $withPayload = false): Event
    {
        $events = $this->createStub(Event::class);
        $events->method('setParam')->willReturnSelf();
        if ($withPayload) {
            $events->method('setPayload')->willReturnSelf();
            $events->method('getProject')->willReturn(new Document(['$id' => 'proj1']));
        }

        return $events;
    }
}
