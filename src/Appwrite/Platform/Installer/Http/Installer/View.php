<?php

namespace Appwrite\Platform\Installer\Http\Installer;

use Appwrite\Platform\Installer\Runtime\Config;
use Appwrite\Platform\Installer\Server;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Http\Adapter\Swoole\Response;
use Utopia\Platform\Action;

class View extends Action
{
    public static function getName(): string
    {
        return 'installerView';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/')
            ->desc('Serve installer UI')
            ->inject('request')
            ->inject('response')
            ->inject('installerConfig')
            ->inject('installerPaths')
            ->callback($this->action(...));
    }

    public function action(Request $request, Response $response, Config $config, array $paths): void
    {
        $csrfToken = $this->makeCsrf($request, $response);

        $response->addHeader('Content-Security-Policy', implode('; ', Server::INSTALLER_CSP));

        $vars = $config->getVars();
        $defaultHttpPort = $config->getDefaultHttpPort();
        $defaultHttpsPort = $config->getDefaultHttpsPort();
        $isUpgrade = $config->isUpgrade();
        $lockedDatabase = $config->getLockedDatabase();
        $isLocalInstall = $config->isLocal();

        $defaultEmailCertificates = $vars['_APP_EMAIL_CERTIFICATES']['default'] ?? '';
        if ($isLocalInstall && empty($defaultEmailCertificates)) {
            $defaultEmailCertificates = 'walterobrien@example.com';
        }

        ob_start();
        include $paths['views'] . '/installer.phtml';
        $html = ob_get_clean();

        $response->html($html);
    }

    private function makeCsrf(Request $request, Response $response): string
    {
        $existing = $request->getCookie(Server::CSRF_COOKIE);
        if ($existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(16));
        $response->addCookie(Server::CSRF_COOKIE, $token, null, '/', null, null, true, Response::COOKIE_SAMESITE_STRICT);
        return $token;
    }
}
