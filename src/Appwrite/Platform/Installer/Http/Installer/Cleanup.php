<?php

namespace Appwrite\Platform\Installer\Http\Installer;

use Appwrite\Platform\Installer\Server;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Http\Adapter\Swoole\Response;
use Utopia\Platform\Action;

class Cleanup extends Action
{
    public static function getName(): string
    {
        return 'installerCleanup';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/install/cleanup')
            ->desc('Cleanup installer container')
            ->inject('request')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Request $request, Response $response): void
    {
        if (!Validate::validateCsrf($request)) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
            $response->json(['success' => false, 'message' => 'Invalid CSRF token']);
            return;
        }

        // Remove the installer container
        $container = escapeshellarg(Server::DEFAULT_CONTAINER);
        @exec("docker rm -f $container >/dev/null 2>&1");

        $response->json(['success' => true]);
    }
}
