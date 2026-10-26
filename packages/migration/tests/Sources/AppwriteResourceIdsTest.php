<?php

namespace Utopia\Migration\Tests\Sources;

use Override;
use PHPUnit\Framework\TestCase;
use Utopia\Migration\Cache;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Auth\Membership;
use Utopia\Migration\Sources\Appwrite;
use Utopia\Migration\Tests\Network\Server;

/**
 * The resource IDs an Appwrite source is held to: a root type lists only its IDs, a
 * child type follows only its listed parents, and a type without IDs is unrestricted.
 * The source runs against the fixture server, which answers the configured JSON and
 * records what was asked for.
 */
final class AppwriteResourceIdsTest extends TestCase
{
    private ?Server $server = null;

    #[Override]
    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testRootTypeListsOnlyItsIds(): void
    {
        $source = $this->source([
            '/v1/users' => ['total' => 0, 'users' => []],
        ]);
        $source->setResourceIds([Resource::TYPE_USER => ['alice', 'bob']]);

        $source->run([Resource::TYPE_USER], fn () => null);

        $this->assertSame([], $source->getErrors());
        $this->assertSame([['alice', 'bob']], $this->idFilters('/v1/users'));
    }

    public function testChildTypeFollowsOnlyItsListedParents(): void
    {
        $source = $this->source([
            '/v1/storage/buckets' => ['total' => 2, 'buckets' => [$this->bucket('assets'), $this->bucket('uploads')]],
            '/v1/storage/buckets/assets/files' => ['total' => 0, 'files' => []],
            '/v1/storage/buckets/uploads/files' => ['total' => 0, 'files' => []],
        ]);
        $source->setResourceIds([Resource::TYPE_FILE => ['assets']]);

        $buckets = [];
        $source->run([Resource::TYPE_BUCKET, Resource::TYPE_FILE], function (array $resources) use (&$buckets) {
            foreach ($resources as $resource) {
                if ($resource instanceof Resource && $resource->getName() === Resource::TYPE_BUCKET) {
                    $buckets[] = $resource->getId();
                }
            }
        });

        $this->assertSame([], $source->getErrors());
        $this->assertSame(['assets', 'uploads'], $buckets, 'Buckets have no IDs of their own and stay unrestricted.');
        $this->assertSame([], $this->idFilters('/v1/storage/buckets'));
        $this->assertCount(1, $this->requests('/v1/storage/buckets/assets/files'));
        $this->assertCount(0, $this->requests('/v1/storage/buckets/uploads/files'), 'Files follow only the listed bucket.');
    }

    public function testMembershipsOfUsersOutsideTheListAreLeftBehind(): void
    {
        $source = $this->source([
            '/v1/users' => ['total' => 1, 'users' => [$this->user('alice')]],
            '/v1/teams' => ['total' => 1, 'teams' => [['$id' => 'admins', 'name' => 'Admins', 'total' => 2, 'prefs' => []]]],
            '/v1/teams/admins/memberships' => ['total' => 2, 'memberships' => [
                ['$id' => 'alice-admins', 'userId' => 'alice', 'teamId' => 'admins', 'roles' => ['owner'], 'confirm' => true],
                ['$id' => 'bob-admins', 'userId' => 'bob', 'teamId' => 'admins', 'roles' => ['member'], 'confirm' => true],
            ]],
        ]);
        $source->setResourceIds([Resource::TYPE_USER => ['alice']]);

        $memberships = [];
        $source->run([Resource::TYPE_USER, Resource::TYPE_TEAM, Resource::TYPE_MEMBERSHIP], function (array $resources) use (&$memberships) {
            foreach ($resources as $resource) {
                if ($resource instanceof Membership) {
                    $memberships[] = $resource->getId();
                }
            }
        });

        $this->assertSame([], $source->getErrors(), 'A membership of a user outside the list is not an error.');
        $this->assertSame(['alice-admins'], $memberships);
    }

    /**
     * @param array<string, array<string, mixed>> $responses
     */
    private function source(array $responses): Appwrite
    {
        $this->server = new Server(['FIXTURE_RESPONSES' => \json_encode($responses, JSON_THROW_ON_ERROR)]);

        $cache = new Cache();
        $source = new Appwrite('project', $this->server->getURL().'/v1', 'key', fn () => throw new \LogicException('No database in this test'));
        $source->registerCache($cache);

        return $source;
    }

    /**
     * @return array<string, mixed>
     */
    private function user(string $id): array
    {
        return ['$id' => $id, 'email' => $id.'@example.com', 'name' => $id, 'prefs' => [], 'targets' => [], 'labels' => []];
    }

    /**
     * @return array<string, mixed>
     */
    private function bucket(string $id): array
    {
        return ['$id' => $id, 'name' => $id, '$permissions' => [], 'fileSecurity' => false, 'enabled' => true, 'maximumFileSize' => 0, 'allowedFileExtensions' => [], 'compression' => 'none', 'encryption' => true, 'antivirus' => true];
    }

    /**
     * The requests the fixture server recorded for a path.
     *
     * @return array<string>
     */
    private function requests(string $path): array
    {
        return \array_values(\array_filter(
            $this->server?->getRequests() ?? [],
            fn (string $request): bool => \str_starts_with($request, 'GET '.$path.'?') || \str_starts_with($request, 'GET '.$path.' '),
        ));
    }

    /**
     * The values of every `$id` equality query sent to a path, one list per query.
     *
     * @return array<array<string>>
     */
    private function idFilters(string $path): array
    {
        $filters = [];

        foreach ($this->requests($path) as $request) {
            $query = \parse_url(\explode(' ', $request)[1], PHP_URL_QUERY) ?: '';
            \parse_str($query, $params);
            $queries = $params['queries'] ?? [];

            foreach (\is_array($queries) ? $queries : [] as $encoded) {
                $query = \is_string($encoded) ? \json_decode($encoded, true) : null;

                if (!\is_array($query) || ($query['method'] ?? '') !== 'equal' || ($query['attribute'] ?? '') !== '$id') {
                    continue;
                }

                $filters[] = \array_map(fn (mixed $value): string => \is_string($value) ? $value : '', (array) $query['values']);
            }
        }

        return $filters;
    }
}
