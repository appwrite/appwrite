<?php

namespace Appwrite\Platform\Installer\Http\Installer;

use Appwrite\Platform\Installer\Runtime\State;
use Appwrite\Platform\Installer\Server;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Http\Adapter\Swoole\Response;
use Utopia\Platform\Action;
use Utopia\Validator\Text;

class Complete extends Action
{
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
            ->param('installId', '', new Text(64, 0), 'Installation ID', true)
            ->param('sessionId', '', new Text(256, 0), 'Session ID', true)
            ->param('sessionSecret', '', new Text(256, 0), 'Session secret', true)
            ->param('sessionExpire', '', new Text(64, 0), 'Session expiry timestamp', true)
            ->inject('request')
            ->inject('response')
            ->inject('installerState')
            ->callback($this->action(...));
    }

    public function action(string $installId, string $sessionId, string $sessionSecret, string $sessionExpire, Request $request, Response $response, State $state): void
    {
        if (!Validate::validateCsrf($request)) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
            $response->json(['success' => false, 'message' => 'Invalid CSRF token']);
            return;
        }

        $installId = $state->sanitizeInstallId($installId);

        if ($installId !== '') {
            $state->updateGlobalLock($installId, Server::STATUS_COMPLETED);
        }

        @touch(Server::INSTALLER_COMPLETE_FILE);

        if (!$sessionSecret && $installId !== '') {
            $data = $state->readProgressFile($installId);
            $details = $data['details'][Server::STEP_ACCOUNT_SETUP] ?? [];
            if (!empty($details['sessionSecret'])) {
                $sessionSecret = $details['sessionSecret'];
                $sessionId = $sessionId ?: ($details['sessionId'] ?? '');
                $sessionExpire = $sessionExpire ?: ($details['sessionExpire'] ?? '');
            }
        }

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
    }
}
