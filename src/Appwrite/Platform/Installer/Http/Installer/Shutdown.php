<?php

namespace Appwrite\Platform\Installer\Http\Installer;

use Swoole\Http\Server as SwooleServer;
use Swoole\Timer;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Http\Adapter\Swoole\Response;
use Utopia\Platform\Action;

class Shutdown extends Action
{
    private const int SHUTDOWN_DELAY_SECONDS = 2;

    public static function getName(): string
    {
        return 'installerShutdown';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/install/shutdown')
            ->desc('Shutdown installer server')
            ->inject('request')
            ->inject('response')
            ->inject('swooleServer')
            ->callback($this->action(...));
    }

    public function action(Request $request, Response $response, ?SwooleServer $swooleServer): void
    {
        if (!Validate::validateCsrf($request)) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
            $response->json(['success' => false, 'message' => 'Invalid CSRF token']);
            return;
        }

        $response->json(['success' => true]);

        if ($swooleServer) {
            Timer::after(self::SHUTDOWN_DELAY_SECONDS * 1000, function () use ($swooleServer) {
                $swooleServer->shutdown();
            });
        }
    }
}
