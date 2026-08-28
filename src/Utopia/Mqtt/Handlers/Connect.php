<?php

namespace Utopia\Mqtt\Handlers;

use Appwrite\Messaging\Adapter\Mqtt;
use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Dispatcher;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\V3;
use Utopia\Mqtt\Packet\V5;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;
use Utopia\Platform\Action;
use Utopia\Span\Span;

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
            ->inject('reply')
            ->callback($this->action(...));
    }

    /**
     * @param (callable(string, string, string): array<string, string>)|null $authenticator
     * @param callable(string, bool): void $reply writes a packet back to this connection (and optionally closes it)
     */
    public function action(?callable $authenticator, Mqtt $mqtt, Connection $connection, Packet $packet, callable $reply): void
    {
        $body = $packet->body;
        $offset = 0;
        [, $offset] = Packet::readString($body, $offset); // protocol name

        $level = ord($body[$offset]);
        $connection->protocol = $level;
        $offset += 1; // protocol level
        $offset += 1; // connect flags
        $offset += 2; // keep alive

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
        $connection->projectId = $projectId;
        Span::add('project.id', $projectId);
        Span::add('mqtt.auth_method', $authMethod);

        // TODO: add abuse limiting keyed on the client ip.
        if ($authenticator !== null) {
            $identity = $authenticator($projectId, $authMethod, $authData);

            if ($identity === []) {
                $mqtt->metrics->connectionsOpened->add(1, ['auth_method' => $authMethod, 'result' => 'rejected']);
                Span::add('mqtt.result', 'rejected');
                $reply($this->connack($level, false), true);
                return;
            }

            $connection->identity = $identity;
            Span::add('user.id', $identity['userId'] ?? '');
        }

        $mqtt->metrics->connectionsOpened->add(1, ['auth_method' => $authMethod, 'result' => 'accepted']);
        $mqtt->metrics->connectionsActive->add(1);
        $connection->active = true;
        $reply($this->connack($level, true), false);
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
