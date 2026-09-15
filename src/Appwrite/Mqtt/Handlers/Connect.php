<?php

namespace Appwrite\Mqtt\Handlers;

use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Mqtt\Dispatcher;
use Appwrite\Mqtt\Response;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit\Redis as TimeLimitRedis;
use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\V3;
use Utopia\Mqtt\Packet\V5;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;
use Utopia\Platform\Action;
use Utopia\Span\Span;
use Utopia\System\System;

class Connect extends Action
{
    public function __construct()
    {
        $this
            ->desc('Authenticate a CONNECT and open the session')
            ->label(Dispatcher::LABEL_TYPE, Packet::CONNECT)
            ->inject('authenticator')
            ->inject('mqtt')
            ->inject('connection')
            ->inject('packet')
            ->inject('response')
            ->callback($this->action(...));
    }

    /**
     * @param (callable(string, string, string): array<string, string>)|null $authenticator
     */
    public function action(?callable $authenticator, Mqtt $mqtt, Connection $connection, Packet $packet, Response $response): void
    {
        $body = $packet->body;
        $offset = 0;
        [, $offset] = Packet::readString($body, $offset); // protocol name

        $level = ord($body[$offset]);
        $connection->protocol = $level;
        $offset += 1; // protocol level
        $offset += 1; // connect flags
        [$keepAlive, $offset] = Packet::readInt16($body, $offset); // keep alive (seconds)
        $connection->keepAlive = $keepAlive;

        $connection->cleanStart = Packet::isCleanStart($body);
        Span::add('mqtt.clean_start', $connection->cleanStart);

        // MQTT 3.1.1 carries no property block; enhanced auth and metadata are 5.0 only.
        $authMethod = '';
        $authData = '';
        $user = [];
        if ($level >= 5) {
            [$properties] = Properties::parse($body, $offset);
            $authMethod = (string) ($properties->get(Property::AUTHENTICATION_METHOD) ?? '');
            $authData = (string) ($properties->get(Property::AUTHENTICATION_DATA) ?? '');
            $user = $properties->user();
        }

        $projectId = $user['projectId'] ?? '';
        $connection->prefix = $projectId;
        Span::add('project.id', $projectId);
        Span::add('mqtt.auth_method', $authMethod);

        if ($authenticator !== null) {
            $start = microtime(true);
            $identity = $authenticator($projectId, $authMethod, $authData);
            $duration = microtime(true) - $start;
            $mqtt->authDuration->record($duration);
            Span::add('mqtt.auth.duration', $duration);

            if ($identity === []) {
                $mqtt->connectionsOpened->add(1, ['auth_method' => $authMethod, 'result' => 'rejected']);
                Span::add('mqtt.result', 'rejected');
                $response->send($this->connack($level, false));
                $response->close();
                return;
            }

            $connection->identity = $identity;
            Span::add('user.id', $identity['userId'] ?? '');

            // Rate-limit CONNECT per user.
            // TODO: in future this will be IP based; currently keyed on userId because the
            // infra does not surface the client IP to the broker.
            if (System::getEnv('_APP_OPTIONS_ABUSE', 'enabled') === 'enabled') {
                $timeLimit = new TimeLimitRedis('mqtt:connect:{userId}', 128, 60, getRedis());
                $timeLimit->setParam('{userId}', $identity['userId'] ?? '');

                if ((new Abuse($timeLimit))->check()) {
                    $mqtt->connectionsOpened->add(1, ['auth_method' => $authMethod, 'result' => 'abuse']);
                    Span::add('mqtt.result', 'abuse');
                    $response->send($this->connack($level, false));
                    $response->close();
                    return;
                }
            }
        }

        // The per-device session anchor: the client-supplied id, or an account-level fallback so
        // reconnects resume the same session (the offline-replay cursor is keyed on it). Resolved
        // here rather than in the library, which only server-assigns a generic id for an empty one.
        $clientId = Packet::getClientId($body);
        if ($clientId === '') {
            $clientId = 'custom_' . $connection->prefix . '_' . ($connection->identity['userId'] ?? '');
        }
        $connection->setClientId($clientId);
        Span::add('mqtt.client_id', $connection->getClientId());

        $mqtt->connectionsOpened->add(1, ['auth_method' => $authMethod, 'result' => 'accepted']);
        $mqtt->connectionsActive->add(1);
        $connection->active = true;
        $response->send($this->connack($level, true));
    }

    /** CONNACK, with the acknowledgement code in each version's own vocabulary. */
    private function connack(int $level, bool $accepted): string
    {
        if ($level >= 5) {
            return V5::connack($accepted ? V5::REASON_SUCCESS : V5::REASON_NOT_AUTHORIZED);
        }

        return V3::connack($accepted ? V3::RETURN_ACCEPTED : V3::RETURN_NOT_AUTHORIZED);
    }
}
