<?php

namespace Appwrite\Mqtt\Handlers;

use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Mqtt\Dispatcher;
use Appwrite\Mqtt\Response;
use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\V5;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;
use Utopia\Platform\Action;
use Utopia\Span\Span;

class Auth extends Action
{
    public function __construct()
    {
        $this
            ->desc('Re-authenticate a live connection with a fresh credential')
            ->label(Dispatcher::LABEL_TYPE, Packet::AUTH)
            ->inject('mqtt')
            ->inject('authenticator')
            ->inject('connection')
            ->inject('packet')
            ->inject('response')
            ->callback($this->action(...));
    }

    /**
     * @param (callable(string, string, string): array<string, string>)|null $authenticator
     */
    public function action(Mqtt $mqtt, ?callable $authenticator, Connection $connection, Packet $packet, Response $response): void
    {
        $body = $packet->body;
        $method = '';
        $data = '';
        $userProperties = [];

        if ($body !== '') {
            // $body[0] is the reason code (0x19 re-authenticate);
            // from offset 1 everything is the MQTT 5.0 property block.
            [$properties] = Properties::parse($body, 1);
            $method = (string) ($properties->get(Property::AUTHENTICATION_METHOD) ?? '');
            $data = (string) ($properties->get(Property::AUTHENTICATION_DATA) ?? '');
            $userProperties = $properties->user();
        }

        // The AUTH packet re-sends projectId as a User Property.
        if ($authenticator !== null) {
            $projectId = $userProperties['projectId'] ?? '';

            $identity = [];
            if ($projectId !== '' && $projectId === $connection->prefix) {
                $start = microtime(true);
                $identity = $authenticator($projectId, $method, $data);
                $mqtt->authDuration->record(microtime(true) - $start);
            }

            // Reauth only refreshes the credential for the identity resolved at CONNECT — it
            // must not switch the user (or project). Allowing a switch would keep the previous
            // user's subscriptions on this connection, leaking their fan-out to the new user
            // and breaking PUBACK ownership checks. A mismatch (or failure) drops the connection.
            if ($identity === [] || ($identity['userId'] ?? '') !== ($connection->identity['userId'] ?? '')) {
                $mqtt->reauth->add(1, ['result' => 'rejected']);
                Span::add('mqtt.result', 'rejected');
                $response->send(V5::disconnect(V5::REASON_NOT_AUTHORIZED));
                $response->close();
                return;
            }

            $connection->identity = $identity;
            $mqtt->reauth->add(1, ['result' => 'success']);
            Span::add('mqtt.result', 'reauthenticated');
        }

        // Acknowledge success on the live connection with an AUTH packet that echoes
        // the authentication method back.
        $properties = new Properties();
        if ($method !== '') {
            $properties->add(new Property(Property::AUTHENTICATION_METHOD, $method));
        }
        $response->send(V5::auth(V5::AUTH_SUCCESS, $properties));
    }
}
