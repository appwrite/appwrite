<?php

namespace Appwrite\Mqtt\Handlers;

use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Mqtt\Connection;
use Appwrite\Mqtt\Dispatcher;
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
            ->inject('reply')
            ->callback($this->action(...));
    }

    /**
     * @param (callable(string, string, string): array<string, string>)|null $authenticator
     * @param callable(string, bool): void $reply writes a packet back to this connection (and optionally closes it)
     */
    public function action(Mqtt $mqtt, ?callable $authenticator, Connection $connection, Packet $packet, callable $reply): void
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
            if ($projectId !== '' && $projectId === $connection->projectId) {
                $start = microtime(true);
                $identity = $authenticator($projectId, $method, $data);
                $mqtt->metrics->authDuration->record(microtime(true) - $start);
            }

            if ($identity === []) {
                $mqtt->metrics->reauth->add(1, ['result' => 'rejected']);
                Span::add('mqtt.result', 'rejected');
                $reply(V5::disconnect(V5::REASON_NOT_AUTHORIZED), true);
                return;
            }

            $connection->identity = $identity;
            $mqtt->metrics->reauth->add(1, ['result' => 'success']);
            Span::add('mqtt.result', 'reauthenticated');
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
