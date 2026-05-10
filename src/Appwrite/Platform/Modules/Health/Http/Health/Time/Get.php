<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\Time;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getTime';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/health/time')
            ->desc('Get time')
            ->groups(['api', 'health'])
            ->label('scope', 'health.read')
            ->label('sdk', new Method(
                namespace: 'health',
                group: 'health',
                name: 'getTime',
                description: '/docs/references/health/get-time.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_HEALTH_TIME,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Response $response): void
    {
        $host = 'time.google.com';
        $gap = 60;

        $sock = \socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        if ($sock === false) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to create socket: ' . \socket_strerror(\socket_last_error()));
        }

        try {
            if (!\socket_connect($sock, $host, 123)) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to connect to time server: ' . \socket_strerror(\socket_last_error($sock)));
            }

            // Set receive timeout to prevent hanging
            if (!\socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0])) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to set socket timeout: ' . \socket_strerror(\socket_last_error($sock)));
            }

            $msg = "\010" . \str_repeat("\0", 47);

            $sent = \socket_send($sock, $msg, \strlen($msg), 0);
            if ($sent === false) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to send NTP request: ' . \socket_strerror(\socket_last_error($sock)));
            }

            $recv = false;
            if (!\socket_recv($sock, $recv, 48, MSG_WAITALL)) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to receive NTP response: ' . \socket_strerror(\socket_last_error($sock)));
            }

            if ($recv === false || \strlen($recv) !== 48) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Invalid NTP response: received ' . (\is_string($recv) ? \strlen($recv) : 'no') . ' bytes instead of 48');
            }

            $data = \unpack('N12', $recv);
            $timestamp = \sprintf('%u', $data[9]);

            $timestamp -= 2208988800;

            $diff = ($timestamp - \time());

            if ($diff > $gap || $diff < ($gap * -1)) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Server time gaps detected');
            }

            $response->dynamic(new Document([
                'remoteTime' => $timestamp,
                'localTime' => \time(),
                'diff' => $diff,
            ]), Response::MODEL_HEALTH_TIME);
        } finally {
            \socket_close($sock);
        }
    }
}
