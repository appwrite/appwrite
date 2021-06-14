<?php

namespace Appwrite\Tests;

use Appwrite\Database\Document;
use Appwrite\Realtime;
use PHPUnit\Framework\TestCase;

class RealtimeChannelsTest extends TestCase
{
    /**
     * Configures how many Connections the Test should Mock.
     */
    public $connectionsPerChannel = 10;

    public $connections = [];
    public $subscriptions = [];
    public $connectionsCount = 0;
    public $connectionsAuthenticated = 0;
    public $connectionsGuest = 0;
    public $connectionsTotal = 0;
    public $allChannels = [
        'files',
        'files.1',
        'collections',
        'collections.1',
        'collections.1.documents',
        'documents',
        'documents.1',
        'executions',
        'executions.1',
        'functions.1',
    ];

    public function setUp(): void
    {
        /**
         * Setup global Counts
         */
        $this->connectionsAuthenticated = count($this->allChannels) * $this->connectionsPerChannel;
        $this->connectionsGuest = count($this->allChannels) * $this->connectionsPerChannel;
        $this->connectionsTotal = $this->connectionsAuthenticated + $this->connectionsGuest;

        /**
         * Add Authenticated Clients
         */
        for ($i = 0; $i < $this->connectionsPerChannel; $i++) {
            foreach ($this->allChannels as $index => $channel) {
                Realtime\Parser::setUser(new Document([
                    '$id' => 'user' . $this->connectionsCount,
                    'memberships' => [
                        [
                            'teamId' => 'team' . $i,
                            'roles' => [
                                empty($index % 2) ? 'admin' : 'member'
                            ]
                        ]
                    ]
                ]));
                $roles = Realtime\Parser::getRoles();
                $parsedChannels = Realtime\Parser::parseChannels([0 => $channel]);

                Realtime\Parser::subscribe(
                    '1',
                    $this->connectionsCount,
                    $roles,
                    $this->subscriptions,
                    $this->connections,
                    $parsedChannels
                );

                $this->connectionsCount++;
            }
        }

        /**
         * Add Guest Clients
         */
        for ($i = 0; $i < $this->connectionsPerChannel; $i++) {
            foreach ($this->allChannels as $index => $channel) {
                Realtime\Parser::setUser(new Document([
                    '$id' => ''
                ]));

                $roles = Realtime\Parser::getRoles();
                $parsedChannels = Realtime\Parser::parseChannels([0 => $channel]);

                Realtime\Parser::subscribe(
                    '1',
                    $this->connectionsCount,
                    $roles,
                    $this->subscriptions,
                    $this->connections,
                    $parsedChannels
                );

                $this->connectionsCount++;
            }
        }
    }

    public function tearDown(): void
    {
        $this->connections = [];
        $this->subscriptions = [];
        $this->connectionsCount = 0;
    }

    public function testSubscriptions()
    {
        /**
         * Check for 1 project.
         */
        $this->assertCount(1, $this->subscriptions);

        /**
         * Check for correct amount of subscriptions:
         *  - XXX users
         *  - XXX teams
         *  - XXX team roles (2 roles per team)
         *  - 1 role:guest
         *  - 1 role:member
         */
        $this->assertCount(($this->connectionsAuthenticated + (3 * $this->connectionsPerChannel) + 2), $this->subscriptions['1']);

        /**
         * Check for connections
         *  - Authenticated
         *  - Guests
         */
        $this->assertCount($this->connectionsTotal, $this->connections);

        Realtime\Parser::unsubscribe(-1, $this->subscriptions, $this->connections);

        $this->assertCount($this->connectionsTotal, $this->connections);
        $this->assertCount(($this->connectionsAuthenticated + (3 * $this->connectionsPerChannel) + 2), $this->subscriptions['1']);

        for ($i = 0; $i < $this->connectionsCount; $i++) {
            Realtime\Parser::unsubscribe($i, $this->subscriptions, $this->connections);

            $this->assertCount(($this->connectionsCount - $i - 1), $this->connections);
        }

        $this->assertEmpty($this->connections);
        $this->assertEmpty($this->subscriptions);
    }

    /**
     * Tests Wildcard (*) Permissions on every channel.
     */
    public function testWildcardPermission()
    {
        foreach ($this->allChannels as $index => $channel) {
            $event = [
                'project' => '1',
                'permissions' => ['*'],
                'data' => [
                    'channels' => [
                        0 => $channel,
                    ]
                ]
            ];

            $receivers = Realtime\Parser::identifyReceivers(
                $event,
                $this->subscriptions
            );

            /**
             * Every Client subscribed to the Wildcard should receive this event.
             */
            $this->assertCount($this->connectionsTotal / count($this->allChannels), $receivers, $channel);

            foreach ($receivers as $receiver) {
                /**
                 * Making sure the right clients receive the event.
                 */
                $this->assertStringEndsWith($index, $receiver);
            }
        }
    }

    public function testRolePermissions()
    {
        $roles = ['role:guest', 'role:member'];
        foreach ($this->allChannels as $index => $channel) {
            foreach ($roles as $role) {
                $permissions = [$role];

                $event = [
                    'project' => '1',
                    'permissions' => $permissions,
                    'data' => [
                        'channels' => [
                            0 => $channel,
                        ]
                    ]
                ];

                $receivers = Realtime\Parser::identifyReceivers(
                    $event,
                    $this->subscriptions
                );

                /**
                 * Every Role subscribed to a Channel should receive this event.
                 */
                $this->assertCount($this->connectionsPerChannel, $receivers, $channel);

                foreach ($receivers as $receiver) {
                    /**
                     * Making sure the right clients receive the event.
                     */
                    $this->assertStringEndsWith($index, $receiver);
                }
            }
        }
    }

    public function testUserPermissions()
    {
        foreach ($this->allChannels as $index => $channel) {
            $permissions = [];
            for ($i = 0; $i < $this->connectionsPerChannel; $i++) {
                $permissions[] = 'user:user' . (!empty($i) ? $i : '') . $index;
            }
            $event = [
                'project' => '1',
                'permissions' => $permissions,
                'data' => [
                    'channels' => [
                        0 => $channel,
                    ]
                ]
            ];

            $receivers = Realtime\Parser::identifyReceivers(
                $event,
                $this->subscriptions
            );

            /**
             * Every Client subscribed to a Channel should receive this event.
             */
            $this->assertCount($this->connectionsAuthenticated / count($this->allChannels), $receivers, $channel);

            foreach ($receivers as $receiver) {
                /**
                 * Making sure the right clients receive the event.
                 */
                $this->assertStringEndsWith($index, $receiver);
            }
        }
    }

    public function testTeamPermissions()
    {
        foreach ($this->allChannels as $index => $channel) {
            $permissions = [];

            for ($i = 0; $i < $this->connectionsPerChannel; $i++) {
                $permissions[] = 'team:team' . $i;
            }
            $event = [
                'project' => '1',
                'permissions' => $permissions,
                'data' => [
                    'channels' => [
                        0 => $channel,
                    ]
                ]
            ];

            $receivers = Realtime\Parser::identifyReceivers(
                $event,
                $this->subscriptions
            );

            /**
             * Every Team Member should receive this event.
             */
            $this->assertCount($this->connectionsAuthenticated / count($this->allChannels), $receivers, $channel);

            foreach ($receivers as $receiver) {
                /**
                 * Making sure the right clients receive the event.
                 */
                $this->assertStringEndsWith($index, $receiver);
            }

            $permissions = ['team:team' . $index . '/' . (empty($index % 2) ? 'admin' : 'member')];

            $event = [
                'project' => '1',
                'permissions' => $permissions,
                'data' => [
                    'channels' => [
                        0 => $channel,
                    ]
                ]
            ];

            $receivers = Realtime\Parser::identifyReceivers(
                $event,
                $this->subscriptions
            );

            /**
             * Only 1 Team Member of a role should have access to a specific channel.
             */
            $this->assertCount(1, $receivers, $channel);

            foreach ($receivers as $receiver) {
                /**
                 * Making sure the right clients receive the event.
                 */
                $this->assertStringEndsWith($index, $receiver);
            }
        }
    }
}
