<?php

namespace Appwrite\Platform\Installer\Http\Installer;

use Appwrite\Platform\Installer\Runtime\State;
use Appwrite\Platform\Installer\Server;
use Swoole\Http\Server as SwooleServer;
use Swoole\Timer;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Http\Adapter\Swoole\Response;
use Utopia\Platform\Action;

class Complete extends Action
{
    private const int SHUTDOWN_DELAY_SECONDS = 5;

    public static function getName(): string
    {
        return 'installerComplete';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/install/complete')
            ->desc('Complete installation')
            ->inject('request')
            ->inject('response')
            ->inject('installerState')
            ->inject('swooleServer')
            ->callback($this->action(...));
    }

    public function action(Request $request, Response $response, State $state, ?SwooleServer $swooleServer): void
    {
        if (!Validate::validateCsrf($request)) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
            $response->json(['success' => false, 'message' => 'Invalid CSRF token']);
            return;
        }

        $input = json_decode($request->getRawPayload(), true);
        if (!is_array($input)) {
            $input = [];
        }
        $installId = $state->sanitizeInstallId($input['installId'] ?? '');
        $sessionId = is_string($input['sessionId'] ?? null) ? $input['sessionId'] : null;
        $sessionSecret = is_string($input['sessionSecret'] ?? null) ? $input['sessionSecret'] : null;
        $sessionExpire = is_string($input['sessionExpire'] ?? null) ? $input['sessionExpire'] : null;

        if ($installId !== '') {
            $state->updateGlobalLock($installId, Server::STATUS_COMPLETED);
        }

        @touch(Server::INSTALLER_COMPLETE_FILE);

        if ($sessionSecret) {
            $isHttps = $request->getProtocol() === 'https';
            $sameSite = $isHttps ? Response::COOKIE_SAMESITE_NONE : Response::COOKIE_SAMESITE_LAX;
            $expires = 0;
            if ($sessionExpire) {
                $timestamp = strtotime($sessionExpire);
                if ($timestamp !== false) {
                    $expires = $timestamp;
                }
            }
            $response->addCookie('a_session_console', $sessionSecret, $expires, '/', '', $isHttps, true, $sameSite);
            $response->addCookie('a_session_console_legacy', $sessionSecret, $expires, '/', '', $isHttps, true, $sameSite);
            if ($sessionId) {
                $response->addHeader('X-Appwrite-Session', $sessionId);
            }
        }

        @unlink(Server::INSTALLER_CONFIG_FILE);

        $response->json(['success' => true]);

        if ($swooleServer) {
            Timer::after(self::SHUTDOWN_DELAY_SECONDS * 1000, function () use ($swooleServer) {
                $swooleServer->shutdown();
            });
        }
    }
}
