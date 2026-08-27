<?php

namespace Utopia\Mqtt\Handlers;

use Appwrite\Messaging\Adapter\Mqtt;
use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Dispatcher;
use Utopia\Mqtt\Packet;
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

        $properties = Packet::readProperties($body, $offset, $level);

        $projectId = $properties['user']['projectId'] ?? '';
        $connection->projectId = $projectId;
        $authMethod = $properties['authMethod'];
        Span::add('project.id', $projectId);
        Span::add('mqtt.auth_method', $authMethod);

        // TODO: add abuse limiting keyed on the client ip.
        if ($authenticator !== null) {
            $identity = $authenticator($projectId, $authMethod, $properties['authData']);

            if ($identity === []) {
                $mqtt->metrics->connectionsOpened->add(1, ['auth_method' => $authMethod, 'result' => 'rejected']);
                Span::add('mqtt.result', 'rejected');
                $reply($this->connack($level, Packet::REASON_NOT_AUTHORIZED), true);
                return;
            }

            $connection->identity = $identity;
            Span::add('user.id', $identity['userId'] ?? '');
        }

        $mqtt->metrics->connectionsOpened->add(1, ['auth_method' => $authMethod, 'result' => 'accepted']);
        $mqtt->metrics->connectionsActive->add(1);
        $connection->active = true;
        $reply($this->connack($level, Packet::REASON_SUCCESS), false);
    }

    private function connack(int $level, int $reasonCode): string
    {
        // CONNACK: [ack flags = 0][reason code](+ [property length = 0] for v5)
        $variable = chr(0x00) . chr($reasonCode) . ($level >= 5 ? Packet::encodeLength(0) : '');

        return chr(Packet::CONNACK << 4) . Packet::encodeLength(strlen($variable)) . $variable;
    }
}
