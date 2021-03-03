<?php

namespace Appwrite\Realtime;

use Appwrite\Auth\Auth;
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
     * @return array
     */
    static function getRoles()
    {
        $roles = ['role:' . ((self::$user->isEmpty()) ? Auth::USER_ROLE_GUEST : Auth::USER_ROLE_MEMBER)];
        if (!(self::$user->isEmpty())) {
            $roles[] = 'user:' . self::$user->getId();
        }
        foreach (self::$user->getAttribute('memberships', []) as $node) {
            if (isset($node['teamId']) && isset($node['roles'])) {
                $roles[] = 'team:' . $node['teamId'];

                foreach ($node['roles'] as $nodeRole) { // Set all team roles
                    $roles[] = 'team:' . $node['teamId'] . '/' . $nodeRole;
                }
            }
        }
        return $roles;
    }

    /**
     * @param array $channels
     */
    static function parseChannels(array $channels)
    {
        $channels = array_flip($channels);

        foreach ($channels as $key => $value) {
            switch (true) {
                case strpos($key, 'account.') === 0:
                    unset($channels[$key]);
                    break;
                
                case $key === 'account':
                    if (!empty(self::$user->getId())) {
                        $channels['account.' . self::$user->getId()] = $value;
                    }
                    unset($channels['account']);
                    break;
            }
        }

        if (\array_key_exists('account', $channels)) {
            if (self::$user->getId()) {
                $channels['account.' . self::$user->getId()] = $channels['account'];
            }
            unset($channels['account']);
        }

        return $channels;
    }

    /**
     * Identifies the receivers of all subscriptions, based on the permissions and event.
     * 
     * @param array $event
     * @param array $connections
     * @param array $subscriptions
     */
    static function identifyReceivers(array &$event, array &$subscriptions)
    {
        $receivers = [];
        if ($subscriptions[$event['project']]) {
            foreach ($subscriptions[$event['project']] as $role => $subscription) {
                foreach ($event['data']['channels'] as $channel) {
                    if (
                        \array_key_exists($channel, $subscriptions[$event['project']][$role])
                        && (\in_array($role, $event['permissions']) || \in_array('*', $event['permissions']))
                    ) {
                        foreach (array_keys($subscriptions[$event['project']][$role][$channel]) as $ids) {
                            $receivers[] = $ids;
                        }
                        break;
                    }
                }
            }
        }

        return array_keys(array_flip($receivers));
    }

    /**
     * Adds Subscription. 
     * 
     * @param string $projectId
     * @param mixed $connection
     * @param array $subscriptions
     * @param array $roles
     * @param array $channels
     */
    static function subscribe($projectId, $connection, $roles, &$subscriptions, &$connections, &$channels)
    {
        /**
         * Build Subscriptions Tree
         * 
         * [PROJECT_ID] -> 
         *      [ROLE_X] -> 
         *          [CHANNEL_NAME_X] -> [CONNECTION_ID]
         *          [CHANNEL_NAME_Y] -> [CONNECTION_ID]
         *          [CHANNEL_NAME_Z] -> [CONNECTION_ID]
         *      [ROLE_Y] -> 
         *          [CHANNEL_NAME_X] -> [CONNECTION_ID]
         *          [CHANNEL_NAME_Y] -> [CONNECTION_ID]
         *          [CHANNEL_NAME_Z] -> [CONNECTION_ID]
         */

        if (!isset($subscriptions[$projectId])) { // Init Project
            $subscriptions[$projectId] = [];
        }

        foreach ($roles as $role) {
            if (!isset($subscriptions[$projectId][$role])) { // Add user first connection
                $subscriptions[$projectId][$role] = [];
            }

            foreach ($channels as $channel => $list) {
                $subscriptions[$projectId][$role][$channel][$connection] = true;
            }
        }

        $connections[$connection] = [
            'projectId' => $projectId,
            'roles' => $roles,
        ];
    }

    /**
     * Remove Subscription. 
     * 
     * @param mixed $connection
     * @param array $subscriptions
     * @param array $connections
     */
    static function unsubscribe($connection, &$subscriptions, &$connections)
    {
        $projectId = $connections[$connection]['projectId'] ?? '';
        $roles = $connections[$connection]['roles'] ?? [];

        foreach ($roles as $role) {
            foreach ($subscriptions[$projectId][$role] as $channel => $list) {
                unset($subscriptions[$projectId][$role][$channel][$connection]); // Remove connection

                if (empty($subscriptions[$projectId][$role][$channel])) {
                    unset($subscriptions[$projectId][$role][$channel]);  // Remove channel when no connections
                }
            }

            if (empty($subscriptions[$projectId][$role])) {
                unset($subscriptions[$projectId][$role]); // Remove role when no channels
            }
        }

        if (empty($subscriptions[$projectId])) { // Remove project when no roles
            unset($subscriptions[$projectId]);
        }

        unset($connections[$connection]);
    }
}
