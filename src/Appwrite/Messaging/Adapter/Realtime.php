<?php

namespace Appwrite\Messaging\Adapter;

use Appwrite\Event\Realtime as EventRealtime;
use Appwrite\Messaging\Adapter;

class Realtime extends Adapter
{
    /**
     * Connection Tree
     * 
     * [CONNECTION_ID] -> 
     *      'projectId' -> [PROJECT_ID]
     *      'roles' -> [ROLE_x, ROLE_Y]
     *      'channels' -> [CHANNEL_NAME_X, CHANNEL_NAME_Y, CHANNEL_NAME_Z]
     */
    public array $connections = [];

    /**
     * Subscription Tree
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
    public array $subscriptions = [];

    /**
     * Adds a subscribtion.
     * @param string $projectId Project ID.
     * @param mixed $connection Unique Identifier - Connection ID.
     * @param array $roles Roles of the Subscription.
     * @param array $channels Subscribed Channels.
     * @return void 
     */
    public function subscribe(string $projectId, mixed $connection, array $roles, array $channels): void
    {
        if (!isset($this->subscriptions[$projectId])) { // Init Project
            $this->subscriptions[$projectId] = [];
        }

        foreach ($roles as $role) {
            if (!isset($this->subscriptions[$projectId][$role])) { // Add user first connection
                $this->subscriptions[$projectId][$role] = [];
            }

            foreach ($channels as $channel => $list) {
                $this->subscriptions[$projectId][$role][$channel][$connection] = true;
            }
        }

        $this->connections[$connection] = [
            'projectId' => $projectId,
            'roles' => $roles,
            'channels' => $channels
        ];
    }

    /**
     * Removes Subscription. 
     * 
     * @param mixed $connection
     * @return void 
     */
    public function unsubscribe(mixed $connection): void
    {
        $projectId = $this->connections[$connection]['projectId'] ?? '';
        $roles = $this->connections[$connection]['roles'] ?? [];

        foreach ($roles as $role) {
            foreach ($this->subscriptions[$projectId][$role] as $channel => $list) {
                unset($this->subscriptions[$projectId][$role][$channel][$connection]); // Remove connection

                if (empty($this->subscriptions[$projectId][$role][$channel])) {
                    unset($this->subscriptions[$projectId][$role][$channel]);  // Remove channel when no connections
                }
            }

            if (empty($this->subscriptions[$projectId][$role])) {
                unset($this->subscriptions[$projectId][$role]); // Remove role when no channels
            }
        }

        if (empty($this->subscriptions[$projectId])) { // Remove project when no roles
            unset($this->subscriptions[$projectId]);
        }

        unset($this->connections[$connection]);
    }

    /**
     * Checks if Channel has a subscriber.
     * @param string $projectId 
     * @param string $role 
     * @param string $channel 
     * @return bool 
     */
    public function hasSubscriber(string $projectId, string $role, string $channel = ''): bool
    {
        if (empty($channel)) {
            return array_key_exists($projectId, $this->subscriptions)
                && array_key_exists($role, $this->subscriptions[$projectId]);
        }

        return array_key_exists($projectId, $this->subscriptions)
            && array_key_exists($role, $this->subscriptions[$projectId])
            && array_key_exists($channel, $this->subscriptions[$projectId][$role]);
    }

    /**
     * Sends an event to the Realtime Server.
     * @param string $projectId 
     * @param string $event 
     * @param array $payload 
     * @return void 
     */
    public function send(string $projectId, string $event, array $payload): void
    {
        $realtime = new EventRealtime($projectId, $event, $payload);
        $realtime->trigger();
    }

    /**
     * Identifies the receivers of all subscriptions, based on the permissions and event.
     * 
     * Example of performance with an event with user:XXX permissions and with X users spread across 10 different channels:
     *  - 0.014 ms (±6.88%) | 10 Connections / 100 Subscriptions 
     *  - 0.070 ms (±3.71%) | 100 Connections / 1,000 Subscriptions 
     *  - 0.846 ms (±2.74%) | 1,000 Connections / 10,000 Subscriptions
     *  - 10.866 ms (±1.01%) | 10,000 Connections / 100,000 Subscriptions
     *  - 110.201 ms (±2.32%) | 100,000 Connections / 1,000,000 Subscriptions
     *  - 1,121.328 ms (±0.84%) | 1,000,000 Connections / 10,000,000 Subscriptions 
     * 
     * @param array $event
     */
    public function getReceivers(array $event)
    {
        $receivers = [];
        if (isset($this->subscriptions[$event['project']])) {
            foreach ($this->subscriptions[$event['project']] as $role => $subscription) {
                foreach ($event['data']['channels'] as $channel) {
                    if (
                        \array_key_exists($channel, $this->subscriptions[$event['project']][$role])
                        && (\in_array($role, $event['permissions']) || \in_array('*', $event['permissions']))
                    ) {
                        foreach (array_keys($this->subscriptions[$event['project']][$role][$channel]) as $ids) {
                            $receivers[$ids] = 0;
                        }
                        break;
                    }
                }
            }
        }

        return array_keys($receivers);
    }
}
