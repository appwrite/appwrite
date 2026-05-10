<?php

namespace Appwrite\Platform\Installer\Http\Installer;

use Appwrite\Platform\Installer\Runtime\Config;
use Appwrite\Platform\Installer\Server;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Http\Adapter\Swoole\Response;
use Utopia\Platform\Action;
use Utopia\Validator\Integer;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

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
            ->param('step', 1, new Integer(true), 'Step number (1-6)', true)
            ->param('partial', null, new Nullable(new Text(1, 0)), 'Render partial step only', true)
            ->inject('request')
            ->inject('response')
            ->inject('installerConfig')
            ->inject('installerPaths')
            ->callback($this->action(...));
    }

    public function action(int $step, ?string $partial, Request $request, Response $response, Config $config, array $paths): void
    {
        $csrfToken = $this->makeCsrf($request, $response);

        $response->addHeader('Content-Security-Policy', implode('; ', Server::INSTALLER_CSP));

        $vars = $config->getVars();
        $defaultHttpPort = $config->getDefaultHttpPort();
        $defaultHttpsPort = $config->getDefaultHttpsPort();
        $isUpgrade = $config->isUpgrade();
        $lockedDatabase = $config->getLockedDatabase();
        $enabledDatabases = $config->getEnabledDatabases();
        $isLocalInstall = $config->isLocal();

        $defaultEmailCertificates = $vars['_APP_EMAIL_CERTIFICATES']['default'] ?? '';
        if ($isLocalInstall && empty($defaultEmailCertificates)) {
            $defaultEmailCertificates = 'walterobrien@example.com';
        }

        $step = max(1, min(6, $step));
        if ($isUpgrade && ($step === 2 || $step === 3)) {
            $step = 4;
        }
        if (!$isUpgrade && $step === 6) {
            $step = 4;
        }

        $partialFile = $paths['views'] . "/installer/templates/steps/step-{$step}.phtml";
        if (!is_file($partialFile)) {
            $partialFile = $paths['views'] . '/installer/templates/steps/step-1.phtml';
        }

        if ($partial !== null) {
            ob_start();
            include $partialFile;
            $html = ob_get_clean();
            $response->html($html);
            return;
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
