<?php

namespace Appwrite\Platform\Installer\Http\Installer;

use Swoole\Http\Server as SwooleServer;
use Swoole\Timer;
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
            ->inject('response')
            ->inject('swooleServer')
            ->callback($this->action(...));
    }

    public function action(Response $response, ?SwooleServer $swooleServer): void
    {
        $response->json(['success' => true]);

        if ($swooleServer) {
            Timer::after(self::SHUTDOWN_DELAY_SECONDS * 1000, function () use ($swooleServer) {
                $swooleServer->shutdown();
            });
        }
    }
}
