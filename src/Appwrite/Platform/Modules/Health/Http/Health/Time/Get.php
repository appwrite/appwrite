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

        \socket_connect($sock, $host, 123);

        $msg = "\010" . \str_repeat("\0", 47);

        \socket_send($sock, $msg, \strlen($msg), 0);

        \socket_recv($sock, $recv, 48, MSG_WAITALL);
        \socket_close($sock);

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
    }
}
