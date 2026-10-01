<?php

namespace Appwrite\Platform\Installer\Http\Installer;

use Appwrite\Platform\Installer\Server;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Http\Adapter\Swoole\Response;
use Utopia\Platform\Action;

class Validate extends Action
{
    public static function getName(): string
    {
        return 'installerValidate';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/install/validate')
            ->desc('Validate CSRF token')
            ->inject('request')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Request $request, Response $response): void
    {
        if (!self::authorize($request, $response)) {
            return;
        }
        $response->json(['success' => true]);
    }

    public static function authorize(Request $request, Response $response): bool
    {
        if (!self::validateSecret($request)) {
            $response->setStatusCode(Response::STATUS_CODE_UNAUTHORIZED);
            $response->json(['success' => false, 'message' => 'Invalid installer secret']);
            return false;
        }

        if (!self::validateCsrf($request)) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
            $response->json(['success' => false, 'message' => 'Invalid CSRF token']);
            return false;
        }

        return true;
    }

    public static function validateCsrf(Request $request): bool
    {
        $cookie = $request->getCookie(Server::CSRF_COOKIE);
        $header = $request->getHeaderLine('x-appwrite-installer-csrf');

        return $cookie !== '' && $header !== '' && hash_equals($cookie, $header);
    }

    public static function validateSecret(Request $request): bool
    {
        $secret = Server::installerSecret();
        $header = $request->getHeaderLine(Server::INSTALLER_SECRET_HEADER);

        return $secret !== '' && $header !== '' && hash_equals($secret, $header);
    }
}
