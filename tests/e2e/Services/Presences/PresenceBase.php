<?php

namespace Tests\E2E\Services\Presences;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

trait PresenceBase
{
    private static array $presenceCache = [];
    private static array $presenceApiKeyCache = [];

    protected function getPresenceApiKey(): string
    {
        $projectId = $this->getProject()['$id'];

        if (!empty(self::$presenceApiKeyCache[$projectId])) {
            return self::$presenceApiKeyCache[$projectId];
        }

        self::$presenceApiKeyCache[$projectId] = $this->getNewKey([
            'presences.read',
            'presences.write',
        ]);

        return self::$presenceApiKeyCache[$projectId];
    }

    /**
     * Server-side helper: ensure presences requests use a presence-scoped API key.
     */
    protected function getPresenceServerHeaders(): array
    {
        $headers = $this->getHeaders(false);

        // Override the project API key added by `SideServer` with a presence-scoped key.
        $headers['x-appwrite-key'] = $this->getPresenceApiKey();

        return $headers;
    }

    protected function setupPresence(array $overrides = []): array
    {
        $projectId = $this->getProject()['$id'];
        $cacheKey = $projectId;

        if (empty($overrides) && !empty(self::$presenceCache[$cacheKey])) {
            return self::$presenceCache[$cacheKey];
        }

        $payload = \array_merge([
            'userId' => $this->getUser()['$id'],
            'status' => 'online',
            'metadata' => [
                'device' => 'web',
                'setup' => true,
            ],
        ], $overrides);

        $response = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . ID::unique(),
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getPresenceApiKey(),
            ],
            $payload
        );
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertArrayHasKey('userId', $response['body']);
        $this->assertArrayHasKey('status', $response['body']);
        $this->assertArrayHasKey('metadata', $response['body']);

        $this->assertEquals($payload['userId'], $response['body']['userId']);

        $canonicalPresence = $this->client->call(
            Client::METHOD_GET,
            '/presences',
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getPresenceApiKey(),
            ],
            [
                'queries' => [
                    Query::equal('userId', [$payload['userId']])->toString(),
                ],
            ]
        );
        $this->assertEquals(200, $canonicalPresence['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $canonicalPresence['body']['total'] ?? 0);
        $this->assertNotEmpty($canonicalPresence['body']['presences'][0] ?? []);

        $presence = $canonicalPresence['body']['presences'][0];

        if (empty($overrides)) {
            self::$presenceCache[$cacheKey] = $presence;
        }

        return $presence;
    }

    protected function resolvePresenceForUser(string $userId, array $headers): array
    {
        $presence = $this->client->call(
            Client::METHOD_GET,
            '/presences',
            $headers,
            [
                'queries' => [
                    Query::equal('userId', [$userId])->toString(),
                ],
            ]
        );

        $this->assertEquals(200, $presence['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $presence['body']['total'] ?? 0);
        $this->assertNotEmpty($presence['body']['presences'][0] ?? []);

        return $presence['body']['presences'][0];
    }

    public function testUpsertAndGetPresence(): void
    {
        if ($this->getSide() === 'client' || $this->getSide() === 'console') {
            $userId = $this->getUser()['$id'];

            $upsert = $this->client->call(
                Client::METHOD_PUT,
                '/presences/' . ID::unique(),
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders(false)),
                [
                    'status' => 'online',
                    'metadata' => ['device' => 'web'],
                ]
            );

            $this->assertEquals(200, $upsert['headers']['status-code']);
            $this->assertNotEmpty($upsert['body']['$id']);
            $this->assertEquals($userId, $upsert['body']['userId']);

            $get = $this->client->call(
                Client::METHOD_GET,
                '/presences/' . $upsert['body']['$id'],
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders(false))
            );

            $this->assertEquals(200, $get['headers']['status-code']);
            $this->assertEquals($upsert['body']['$id'], $get['body']['$id']);
            $this->assertEquals($userId, $get['body']['userId']);
            $this->assertArrayHasKey('expiresAt', $get['body']);

            return;
        }

        $presence = $this->setupPresence();

        $get = $this->client->call(
            Client::METHOD_GET,
            '/presences/' . $presence['$id'],
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getPresenceServerHeaders())
        );

        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals($presence['$id'], $get['body']['$id']);
        $this->assertEquals($presence['userId'], $get['body']['userId']);
        $this->assertArrayHasKey('expiresAt', $get['body']);
    }

    public function testListPresences(): void
    {
        if ($this->getSide() === 'client' || $this->getSide() === 'console') {
            $upsert = $this->client->call(
                Client::METHOD_PUT,
                '/presences/' . ID::unique(),
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders(false)),
                [
                    'status' => 'online',
                    'metadata' => ['device' => 'web'],
                ]
            );

            $this->assertEquals(200, $upsert['headers']['status-code']);
            $this->assertNotEmpty($upsert['body']['$id']);
            $this->assertArrayHasKey('userId', $upsert['body']);

            $list = $this->client->call(
                Client::METHOD_GET,
                '/presences',
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders(false)),
                [
                    'queries' => [
                        Query::equal('userId', [$upsert['body']['userId']])->toString(),
                    ],
                ]
            );

            $this->assertEquals(200, $list['headers']['status-code']);
            $this->assertArrayHasKey('total', $list['body']);
            $this->assertArrayHasKey('presences', $list['body']);
            $this->assertIsArray($list['body']['presences']);
            $this->assertGreaterThanOrEqual(1, $list['body']['total']);

            // Client sessions must not be able to list presences belonging to a different user.
            $projectId = $this->getProject()['$id'];
            $originalUser = $this->getUser();
            $otherUser = $this->getUser(true);
            $otherUserId = $otherUser['$id'];

            // Important: don't let `getUser(true)` overwrite the cached user/session for the rest
            // of this test run.
            self::$user[$projectId] = $originalUser;

            if ($projectId === 'console') {
                // The console project has no API keys; seed via the other user's own session.
                $this->client->call(
                    Client::METHOD_PUT,
                    '/presences/' . ID::unique(),
                    [
                        'content-type' => 'application/json',
                        'x-appwrite-project' => $projectId,
                        'cookie' => 'a_session_' . $projectId . '=' . $otherUser['session'],
                    ],
                    [
                        'status' => 'online',
                        'metadata' => ['device' => 'other-user'],
                    ]
                );
            } else {
                // Seed another presence for the other user (setup via API key, not the client session).
                $this->setupPresence([
                    'userId' => $otherUserId,
                    'status' => 'online',
                    'metadata' => ['device' => 'other-user'],
                ]);
            }

            $otherList = $this->client->call(
                Client::METHOD_GET,
                '/presences',
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders(false)),
                [
                    'queries' => [
                        Query::equal('userId', [$otherUserId])->toString(),
                    ],
                ]
            );

            $this->assertEquals(200, $otherList['headers']['status-code']);
            $this->assertArrayHasKey('total', $otherList['body']);
            $this->assertArrayHasKey('presences', $otherList['body']);
            $this->assertSame([], $otherList['body']['presences']);
            $this->assertEquals(0, $otherList['body']['total']);
            return;
        }

        $presence = $this->setupPresence();

        $list = $this->client->call(
            Client::METHOD_GET,
            '/presences',
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getPresenceServerHeaders()),
            [
                'queries' => [
                    Query::equal('userId', [$presence['userId']])->toString(),
                ],
            ]
        );

        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertArrayHasKey('total', $list['body']);
        $this->assertArrayHasKey('presences', $list['body']);
        $this->assertIsArray($list['body']['presences']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);
    }

    public function testClientPresenceCustomPermissionsForOtherUser(): void
    {
        // Requires API key to create two concurrent presences for the same user with
        // different ACLs. Server-only — also skipped on console (which has no API keys).
        if ($this->getSide() !== 'client') {
            $this->expectNotToPerformAssertions();
            return;
        }

        $projectId = $this->getProject()['$id'];
        $user1 = $this->getUser(true);
        $user2 = $this->getUser(true);
        $headersUser2 = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $user2['session'],
        ];

        $permissionsForUser2 = [
            Permission::read(Role::user($user2['$id'])),
            Permission::update(Role::user($user2['$id'])),
            Permission::delete(Role::user($user2['$id'])),
            Permission::write(Role::user($user2['$id'])),
        ];

        $permissionsForUser1 = [
            Permission::read(Role::user($user1['$id'])),
            Permission::update(Role::user($user1['$id'])),
            Permission::delete(Role::user($user1['$id'])),
            Permission::write(Role::user($user1['$id'])),
        ];

        // Create a presence for user1 using a presence-scoped API key so we can set ACLs.
        $presenceAllow = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . ID::unique(),
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getPresenceApiKey(),
            ]),
            [
                'userId' => $user1['$id'],
                'status' => 'online',
                'metadata' => ['case' => 'allow'],
                // Owner always retains full permissions; user2 additionally gets access.
                'permissions' => \array_merge($permissionsForUser1, $permissionsForUser2),
            ]
        );

        $this->assertEquals(200, $presenceAllow['headers']['status-code']);
        $presenceIdAllow = $presenceAllow['body']['$id'];

        // user2 can read
        $get = $this->client->call(
            Client::METHOD_GET,
            '/presences/' . $presenceIdAllow,
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $headersUser2)
        );
        $this->assertEquals(200, $get['headers']['status-code']);

        // user2 can update
        $patch = $this->client->call(
            Client::METHOD_PATCH,
            '/presences/' . $presenceIdAllow,
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $headersUser2),
            [
                'status' => 'busy',
                'metadata' => ['case' => 'allow-update'],
            ]
        );
        $this->assertEquals(200, $patch['headers']['status-code']);
        $this->assertEquals('busy', $patch['body']['status']);

        // user2 can delete
        $delete = $this->client->call(
            Client::METHOD_DELETE,
            '/presences/' . $presenceIdAllow,
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $headersUser2)
        );
        $this->assertEquals(204, $delete['headers']['status-code']);

        // Create another presence for user1 without granting any special permissions to user2.
        $presenceDeny = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . ID::unique(),
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getPresenceApiKey(),
            ]),
            [
                'userId' => $user1['$id'],
                'status' => 'online',
                'metadata' => ['case' => 'deny'],
                // Only the owner has permissions; user2 should not be able to access this document.
                'permissions' => $permissionsForUser1,
            ]
        );

        $this->assertEquals(200, $presenceDeny['headers']['status-code']);
        $presenceIdDeny = $presenceDeny['body']['$id'];

        // user2 cannot read
        $getDeny = $this->client->call(
            Client::METHOD_GET,
            '/presences/' . $presenceIdDeny,
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $headersUser2)
        );
        // When read permission is missing, the document should be treated as not found.
        $this->assertEquals(404, $getDeny['headers']['status-code']);

        // user2 cannot update
        $patchDeny = $this->client->call(
            Client::METHOD_PATCH,
            '/presences/' . $presenceIdDeny,
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $headersUser2),
            [
                'status' => 'busy',
            ]
        );
        $this->assertEquals(404, $patchDeny['headers']['status-code']);

        // user2 cannot delete
        $deleteDeny = $this->client->call(
            Client::METHOD_DELETE,
            '/presences/' . $presenceIdDeny,
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $headersUser2)
        );
        $this->assertEquals(404, $deleteDeny['headers']['status-code']);
    }

    public function testUpdatePresenceSparseFields(): void
    {
        if ($this->getSide() === 'client' || $this->getSide() === 'console') {
            $upsert = $this->client->call(
                Client::METHOD_PUT,
                '/presences/' . ID::unique(),
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders(false)),
                [
                    'status' => 'away',
                    'metadata' => ['source' => 'setup'],
                ]
            );

            $this->assertEquals(200, $upsert['headers']['status-code']);
            $presence = $this->resolvePresenceForUser(
                $upsert['body']['userId'],
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders(false))
            );
            $presenceId = $presence['$id'];

            $update = $this->client->call(
                Client::METHOD_PATCH,
                '/presences/' . $presenceId,
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders(false)),
                [
                    'status' => 'busy',
                    'metadata' => ['source' => 'update'],
                ]
            );

            $this->assertEquals(200, $update['headers']['status-code']);
            $this->assertEquals('busy', $update['body']['status']);
            $this->assertEquals(['source' => 'update'], $update['body']['metadata']);

            return;
        }

        $presence = $this->setupPresence([
            'status' => 'away',
            'metadata' => ['source' => 'setup'],
        ]);

        $payload = [
            'status' => 'busy',
            'metadata' => ['source' => 'update'],
        ];

        if ($this->getSide() === 'server') {
            $payload['userId'] = $presence['userId'];
        }

        $update = $this->client->call(
            Client::METHOD_PATCH,
            '/presences/' . $presence['$id'],
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getPresenceServerHeaders()),
            $payload
        );

        $this->assertEquals(200, $update['headers']['status-code']);
        $this->assertEquals('busy', $update['body']['status']);
        $this->assertEquals(['source' => 'update'], $update['body']['metadata']);
    }

    public function testUpdatePresenceUserIdReassignsDefaultPermissions(): void
    {
        if ($this->getSide() !== 'server') {
            $this->expectNotToPerformAssertions();
            return;
        }

        $projectId = $this->getProject()['$id'];
        $user1 = $this->getUser(true);
        $user2 = $this->getUser(true);

        $headersUser1 = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $user1['session'],
        ];

        $headersUser2 = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $user2['session'],
        ];

        $create = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . ID::unique(),
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $headersUser1),
            [
                'status' => 'online',
                'metadata' => ['owner' => 'user1'],
            ]
        );

        $this->assertEquals(200, $create['headers']['status-code']);
        $presence = $this->resolvePresenceForUser(
            $user1['$id'],
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $headersUser1)
        );

        $reassign = $this->client->call(
            Client::METHOD_PATCH,
            '/presences/' . $presence['$id'],
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $this->getPresenceServerHeaders()),
            [
                'userId' => $user2['$id'],
                'status' => 'busy',
            ]
        );

        $this->assertEquals(200, $reassign['headers']['status-code']);
        $this->assertSame($user2['$id'], $reassign['body']['userId']);

        $getOldOwner = $this->client->call(
            Client::METHOD_GET,
            '/presences/' . $presence['$id'],
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $headersUser1)
        );
        $this->assertEquals(404, $getOldOwner['headers']['status-code']);

        $getNewOwner = $this->client->call(
            Client::METHOD_GET,
            '/presences/' . $presence['$id'],
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $headersUser2)
        );
        $this->assertEquals(200, $getNewOwner['headers']['status-code']);
        $this->assertSame($user2['$id'], $getNewOwner['body']['userId']);

        $patchOldOwner = $this->client->call(
            Client::METHOD_PATCH,
            '/presences/' . $presence['$id'],
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $headersUser1),
            [
                'status' => 'offline',
            ]
        );
        $this->assertEquals(404, $patchOldOwner['headers']['status-code']);

        $patchNewOwner = $this->client->call(
            Client::METHOD_PATCH,
            '/presences/' . $presence['$id'],
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $headersUser2),
            [
                'status' => 'away',
            ]
        );
        $this->assertEquals(200, $patchNewOwner['headers']['status-code']);
        $this->assertSame('away', $patchNewOwner['body']['status']);
    }

    public function testDeletePresence(): void
    {
        if ($this->getSide() === 'client' || $this->getSide() === 'console') {
            $upsert = $this->client->call(
                Client::METHOD_PUT,
                '/presences/' . ID::unique(),
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders(false)),
                [
                    'status' => 'temp-delete',
                    'metadata' => ['cleanup' => true],
                ]
            );

            $this->assertEquals(200, $upsert['headers']['status-code']);
            $presence = $this->resolvePresenceForUser(
                $upsert['body']['userId'],
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders(false))
            );
            $presenceId = $presence['$id'];

            $delete = $this->client->call(
                Client::METHOD_DELETE,
                '/presences/' . $presenceId,
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders(false))
            );

            $this->assertEquals(204, $delete['headers']['status-code']);

            return;
        }

        $presence = $this->setupPresence([
            'status' => 'temp-delete',
            'metadata' => ['cleanup' => true],
        ]);

        $delete = $this->client->call(
            Client::METHOD_DELETE,
            '/presences/' . $presence['$id'],
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getPresenceServerHeaders())
        );

        $this->assertEquals(204, $delete['headers']['status-code']);
    }

    public function testUpdatePresencePurgeListCache(): void
    {
        if ($this->getProject()['$id'] === 'console') {
            // The console project shares dbForPlatform's cache with every other request,
            // so parallel workers can wipe the list cache between calls and the hit/miss
            // assertions become flaky. Skip on console.
            $this->expectNotToPerformAssertions();
            return;
        }

        if ($this->getSide() === 'client') {
            $upsert = $this->client->call(
                Client::METHOD_PUT,
                '/presences/' . ID::unique(),
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders(false)),
                [
                    'status' => 'cache-update-setup',
                    'metadata' => ['cache' => 'update-setup'],
                ]
            );
            $this->assertEquals(200, $upsert['headers']['status-code']);
            $headers = \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders(false));
            $presence = $this->resolvePresenceForUser($upsert['body']['userId'], $headers);
        } else {
            $presence = $this->setupPresence([
                'status' => 'cache-update-setup',
                'metadata' => ['cache' => 'update-setup'],
            ]);
            $headers = \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getPresenceServerHeaders());
        }

        $listPayload = [
            'queries' => [
                Query::equal('userId', [$presence['userId']])->toString(),
            ],
            'ttl' => 60,
        ];

        $list1 = $this->client->call(Client::METHOD_GET, '/presences', $headers, $listPayload);
        $this->assertEquals(200, $list1['headers']['status-code']);
        $this->assertArrayHasKey('x-appwrite-cache', $list1['headers']);

        $list2 = $this->client->call(Client::METHOD_GET, '/presences', $headers, $listPayload);
        $this->assertEquals(200, $list2['headers']['status-code']);
        $this->assertArrayHasKey('x-appwrite-cache', $list2['headers']);
        $this->assertEquals('hit', $list2['headers']['x-appwrite-cache']);

        $updatePayload = [
            'status' => 'cache-update-applied',
            'purge' => true,
        ];

        if ($this->getSide() !== 'client') {
            $updatePayload['userId'] = $presence['userId'];
        }

        $update = $this->client->call(
            Client::METHOD_PATCH,
            '/presences/' . $presence['$id'],
            $headers,
            $updatePayload
        );
        $this->assertEquals(200, $update['headers']['status-code']);
        $this->assertEquals('cache-update-applied', $update['body']['status']);

        $list3 = $this->client->call(Client::METHOD_GET, '/presences', $headers, $listPayload);
        $this->assertEquals(200, $list3['headers']['status-code']);
        $this->assertArrayHasKey('x-appwrite-cache', $list3['headers']);
        $this->assertEquals('miss', $list3['headers']['x-appwrite-cache']);
    }

    public function testUpdatePresencePurgeOnlyListCache(): void
    {
        if ($this->getProject()['$id'] === 'console') {
            $this->expectNotToPerformAssertions();
            return;
        }

        if ($this->getSide() === 'client') {
            $upsert = $this->client->call(
                Client::METHOD_PUT,
                '/presences/' . ID::unique(),
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders(false)),
                [
                    'status' => 'cache-purge-only-setup',
                    'metadata' => ['cache' => 'purge-only-setup'],
                ]
            );
            $this->assertEquals(200, $upsert['headers']['status-code']);
            $headers = \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders(false));
            $presence = $this->resolvePresenceForUser($upsert['body']['userId'], $headers);
        } else {
            $presence = $this->setupPresence([
                'status' => 'cache-purge-only-setup',
                'metadata' => ['cache' => 'purge-only-setup'],
            ]);
            $headers = \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getPresenceServerHeaders());
        }

        $listPayload = [
            'queries' => [
                Query::equal('userId', [$presence['userId']])->toString(),
            ],
            'ttl' => 60,
        ];

        $list1 = $this->client->call(Client::METHOD_GET, '/presences', $headers, $listPayload);
        $this->assertEquals(200, $list1['headers']['status-code']);
        $this->assertArrayHasKey('x-appwrite-cache', $list1['headers']);

        $list2 = $this->client->call(Client::METHOD_GET, '/presences', $headers, $listPayload);
        $this->assertEquals(200, $list2['headers']['status-code']);
        $this->assertArrayHasKey('x-appwrite-cache', $list2['headers']);
        $this->assertEquals('hit', $list2['headers']['x-appwrite-cache']);

        $updatePayload = [
            'purge' => true,
        ];

        if ($this->getSide() !== 'client') {
            $updatePayload['userId'] = $presence['userId'];
        }

        $update = $this->client->call(
            Client::METHOD_PATCH,
            '/presences/' . $presence['$id'],
            $headers,
            $updatePayload
        );
        $this->assertEquals(200, $update['headers']['status-code']);
        $this->assertEquals($presence['$id'], $update['body']['$id']);

        $list3 = $this->client->call(Client::METHOD_GET, '/presences', $headers, $listPayload);
        $this->assertEquals(200, $list3['headers']['status-code']);
        $this->assertArrayHasKey('x-appwrite-cache', $list3['headers']);
        $this->assertEquals('miss', $list3['headers']['x-appwrite-cache']);
    }

    public function testDeletePresencePurgesListCache(): void
    {
        if ($this->getProject()['$id'] === 'console') {
            $this->expectNotToPerformAssertions();
            return;
        }

        if ($this->getSide() === 'client') {
            $upsert = $this->client->call(
                Client::METHOD_PUT,
                '/presences/' . ID::unique(),
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders(false)),
                [
                    'status' => 'cache-delete-setup',
                    'metadata' => ['cache' => 'delete-setup'],
                ]
            );
            $this->assertEquals(200, $upsert['headers']['status-code']);
            $headers = \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders(false));
            $presence = $this->resolvePresenceForUser($upsert['body']['userId'], $headers);
        } else {
            $presence = $this->setupPresence([
                'status' => 'cache-delete-setup',
                'metadata' => ['cache' => 'delete-setup'],
            ]);
            $headers = \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getPresenceServerHeaders());
        }

        $listPayload = [
            'queries' => [
                Query::equal('userId', [$presence['userId']])->toString(),
            ],
            'ttl' => 60,
        ];

        $list1 = $this->client->call(Client::METHOD_GET, '/presences', $headers, $listPayload);
        $this->assertEquals(200, $list1['headers']['status-code']);
        $this->assertArrayHasKey('x-appwrite-cache', $list1['headers']);

        $list2 = $this->client->call(Client::METHOD_GET, '/presences', $headers, $listPayload);
        $this->assertEquals(200, $list2['headers']['status-code']);
        $this->assertArrayHasKey('x-appwrite-cache', $list2['headers']);
        $this->assertEquals('hit', $list2['headers']['x-appwrite-cache']);

        $delete = $this->client->call(
            Client::METHOD_DELETE,
            '/presences/' . $presence['$id'],
            $headers
        );
        $this->assertEquals(204, $delete['headers']['status-code']);

        $list3 = $this->client->call(Client::METHOD_GET, '/presences', $headers, $listPayload);
        $this->assertEquals(200, $list3['headers']['status-code']);
        $this->assertArrayHasKey('x-appwrite-cache', $list3['headers']);
        $this->assertEquals('miss', $list3['headers']['x-appwrite-cache']);
    }

    public function testUpdateNotFound(): void
    {
        if ($this->getSide() === 'client' || $this->getSide() === 'console') {
            $response = $this->client->call(
                Client::METHOD_PATCH,
                '/presences/' . ID::unique(),
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders(false)),
                [
                    'status' => 'ghost',
                ]
            );

            $this->assertEquals(404, $response['headers']['status-code']);
            return;
        }

        $payload = [
            'status' => 'ghost',
        ];

        if ($this->getSide() === 'server') {
            $payload['userId'] = $this->getUser()['$id'];
        }

        $response = $this->client->call(
            Client::METHOD_PATCH,
            '/presences/' . ID::unique(),
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getPresenceServerHeaders()),
            $payload
        );

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    public function testClientCannotPassUserId(): void
    {
        if ($this->getSide() === 'server') {
            $this->expectNotToPerformAssertions();
            return;
        }

        $response = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . ID::unique(),
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders(false)),
            [
                'userId' => ID::unique(),
                'status' => 'online',
            ]
        );

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testServerRequiresUserId(): void
    {
        // Server-only behavior — also skipped on console (no API keys for the console project).
        if ($this->getSide() === 'client' || $this->getSide() === 'console') {
            $this->expectNotToPerformAssertions();
            return;
        }

        $response = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . ID::unique(),
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getPresenceServerHeaders()),
            [
                'status' => 'online',
            ]
        );

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testUpsertSameUserMaintainsSinglePresence(): void
    {
        // Server-only behavior — also skipped on console (no API keys for the console project).
        if ($this->getSide() === 'client' || $this->getSide() === 'console') {
            $this->expectNotToPerformAssertions();
            return;
        }

        $projectId = $this->getProject()['$id'];
        $userId = $this->getUser()['$id'];
        $headers = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getPresenceServerHeaders());

        $firstUpsert = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . ID::unique(),
            $headers,
            [
                'userId' => $userId,
                'status' => 'online',
                'metadata' => ['source' => 'first-upsert'],
            ]
        );
        $this->assertEquals(200, $firstUpsert['headers']['status-code']);

        $secondUpsert = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . ID::unique(),
            $headers,
            [
                'userId' => $userId,
                'status' => 'away',
                'metadata' => ['source' => 'second-upsert'],
            ]
        );
        $this->assertEquals(200, $secondUpsert['headers']['status-code']);

        $this->assertEquals('away', $secondUpsert['body']['status']);
        $this->assertEquals(['source' => 'second-upsert'], $secondUpsert['body']['metadata']);

        $list = $this->client->call(
            Client::METHOD_GET,
            '/presences',
            $headers,
            [
                'queries' => [
                    Query::equal('userId', [$userId])->toString(),
                ],
            ]
        );

        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertEquals(1, $list['body']['total']);
        $this->assertCount(1, $list['body']['presences']);
        $this->assertEquals($userId, $list['body']['presences'][0]['userId']);
        $this->assertEquals('away', $list['body']['presences'][0]['status']);
    }

    /**
     * Regression test for cross-user overwrite on the native-upsert path.
     *
     * Scenario:
     *   - User A has a presence row with $id = $sharedPresenceId.
     *   - User B (different userInternalId, no existing presence) issues an upsert that
     *     re-uses $sharedPresenceId.
     *
     * Without the ownership guard in State::upsertForUser, the second call would silently
     * UPDATE A's row (because upsertDocument matches on the primary key) leaving B's data
     * under A's $id. With the guard, the second call must fail with PRESENCE_ALREADY_EXISTS
     * and A's row must be untouched.
     */
    public function testCrossUserUpsertDoesNotOverwriteForeignPresence(): void
    {
        if ($this->getSide() !== 'client' && $this->getSide() !== 'console') {
            $this->expectNotToPerformAssertions();
            return;
        }

        $projectId = $this->getProject()['$id'];
        $originalUser = $this->getUser();

        $user1 = $this->getUser(true);
        $user2 = $this->getUser(true);

        // Preserve the cached session for the rest of the test run.
        self::$user[$projectId] = $originalUser;

        $headersUser1 = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $user1['session'],
        ];
        $headersUser2 = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $user2['session'],
        ];

        $sharedPresenceId = ID::unique();

        $victim = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . $sharedPresenceId,
            $headersUser1,
            [
                'status' => 'online',
                'metadata' => ['owner' => 'user1'],
            ]
        );
        $this->assertEquals(200, $victim['headers']['status-code']);
        $this->assertEquals($sharedPresenceId, $victim['body']['$id']);
        $this->assertEquals($user1['$id'], $victim['body']['userId']);

        $attack = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . $sharedPresenceId,
            $headersUser2,
            [
                'status' => 'online',
                'metadata' => ['owner' => 'user2'],
            ]
        );
        $this->assertNotEquals(
            200,
            $attack['headers']['status-code'],
            'Cross-user upsert must not succeed silently. Got body: ' . \json_encode($attack['body'] ?? [])
        );

        // Verify User1's row is intact. Read via a presence-scoped API key to bypass
        // any read-permission ambiguity and inspect the persisted state directly.
        // The console project has no API keys, so fall back to user1's own session —
        // if the bug ever resurfaces and user2 overwrote the row, user1 would lose
        // read permission and this GET would return 404, still surfacing the failure.
        $checkHeaders = $projectId === 'console'
            ? $headersUser1
            : [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getPresenceApiKey(),
            ];
        $check = $this->client->call(
            Client::METHOD_GET,
            '/presences/' . $sharedPresenceId,
            $checkHeaders
        );
        $this->assertEquals(200, $check['headers']['status-code']);
        $this->assertEquals($user1['$id'], $check['body']['userId']);
        $this->assertEquals(['owner' => 'user1'], $check['body']['metadata']);
    }
}
