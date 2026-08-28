<?php

namespace Utopia\Mqtt\Handlers;

use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Dispatcher;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\V5;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;
use Utopia\Platform\Action;

class Auth extends Action
{
    public function __construct()
    {
        $this
            ->desc('Re-authenticate a live connection with a fresh credential')
            ->label(Dispatcher::LABEL_TYPE, Packet::AUTH)
            ->inject('authenticator')
            ->inject('connection')
            ->inject('packet')
            ->inject('reply')
            ->callback($this->action(...));
    }

    /**
     * @param (callable(string, string, string): array<string, string>)|null $authenticator
     * @param callable(string, bool): void $reply writes a packet back to this connection (and optionally closes it)
     */
    public function action(?callable $authenticator, Connection $connection, Packet $packet, callable $reply): void
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
        // TODO: check the events for the reauth only refreshes
        // the credential, so enforce it stays on the project resolved at CONNECT — the
        // connection must not switch tenants — then verify the fresh credential.
        if ($authenticator !== null) {
            $projectId = $userProperties['projectId'] ?? '';

            $identity = ($projectId !== '' && $projectId === $connection->projectId)
                ? $authenticator($projectId, $method, $data)
                : [];

            if ($identity === []) {
                $reply(V5::disconnect(V5::REASON_NOT_AUTHORIZED), true);
                return;
            }

            $connection->identity = $identity;
        }

        // Acknowledge success on the live connection with an AUTH packet that echoes
        // the authentication method back.
        $properties = new Properties();
        if ($method !== '') {
            $properties->add(new Property(Property::AUTHENTICATION_METHOD, $method));
        }
        $reply(V5::auth(V5::AUTH_SUCCESS, $properties), false);
    }
}
