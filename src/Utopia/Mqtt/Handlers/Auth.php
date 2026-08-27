<?php

namespace Utopia\Mqtt\Handlers;

use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Dispatcher;
use Utopia\Mqtt\Packet;
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
            // $body[0] is the reason code (0x19 re-authenticate)
            // from the offset 1 everything is the mqtt v5 properties
            $properties = Packet::readProperties($body, 1, $connection->protocol);
            $method = $properties['authMethod'];
            $data = $properties['authData'];
            $userProperties = $properties['user'];
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
                $reply($this->disconnect(Packet::REASON_NOT_AUTHORIZED), true);
                return;
            }

            $connection->identity = $identity;
        }

        // Acknowledge success on the live connection with an AUTH packet.
        $reply($this->auth(Packet::AUTH_SUCCESS, $method), false);
    }

    private function disconnect(int $reasonCode): string
    {
        return chr(Packet::DISCONNECT << 4) . Packet::encodeLength(1) . chr($reasonCode);
    }

    private function auth(int $reasonCode, string $method, string $data = ''): string
    {
        $properties = '';
        if ($method !== '') {
            $properties .= chr(Packet::PROP_AUTH_METHOD) . Packet::encodeString($method);
        }
        if ($data !== '') {
            $properties .= chr(Packet::PROP_AUTH_DATA) . Packet::encodeString($data);
        }

        $variable = chr($reasonCode) . Packet::encodeLength(strlen($properties)) . $properties;

        return chr(Packet::AUTH << 4) . Packet::encodeLength(strlen($variable)) . $variable;
    }
}
