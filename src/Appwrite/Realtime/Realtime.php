<?php

namespace Appwrite\Realtime;

use Appwrite\Database\Document;

class Realtime
{
    /**
     * @var Document $user
     */
    static $user;

    /**
     * @param Document $user
     */
    static function setUser(Document $user)
    {
        self::$user = $user;
    }

    /**
     * @param array $channels
     */
    static function parseChannels(array &$channels)
    {
        foreach ($channels as $key => $value) {
            if (strpos($key, 'account.') === 0) {
                unset($channels[$key]);
            } elseif ($key === 'account') {
                if (!empty(self::$user->getId())) {
                    $channels['account.' . self::$user->getId()] = $value;
                }
                unset($channels['account']);
            }
        }

        if (\array_key_exists('account', $channels)) {
            if (self::$user->getId()) {
                $channels['account.' . self::$user->getId()] = $channels['account'];
            }
            unset($channels['account']);
        }
    }

    /**
     * @param array $roles
     */
    static function parseRoles(array &$roles)
    {
        \array_map(function ($node) use (&$roles) {
            if (isset($node['teamId']) && isset($node['roles'])) {
                $roles[] = 'team:' . $node['teamId'];

                foreach ($node['roles'] as $nodeRole) { // Set all team roles
                    $roles[] = 'team:' . $node['teamId'] . '/' . $nodeRole;
                }
            }
        }, self::$user->getAttribute('memberships', []));
    }

    /**
     * Identifies the receivers of all subscriptions, based on the permissions and event.
     * 
     * @param array $event
     * @param array $connections
     * @param array $subscriptions
     */
    static function identifyReceivers(array &$event, array &$connections, array &$subscriptions)
    {
        $receivers = [];
        foreach ($connections as $fd => $connection) {
            if ($connection['projectId'] !== $event['project']) {
                continue;
            }

            foreach ($connection['roles'] as $role) {
                if (\array_key_exists($role, $subscriptions[$event['project']])) {
                    foreach ($event['data']['channels'] as $channel) {
                        if (\array_key_exists($channel, $subscriptions[$event['project']][$role]) && \in_array($role, $event['permissions'])) {
                            foreach (array_keys($subscriptions[$event['project']][$role][$channel]) as $ids) {
                                $receivers[] = $ids;
                            }
                            break;
                        }
                    }
                }
            }
        }

        return array_keys(array_flip($receivers));
    }
}
