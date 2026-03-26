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

        $progressData = ($installId !== '') ? $state->readProgressFile($installId) : [];

        if (!$sessionSecret) {
            $details = $progressData['details'][Server::STEP_ACCOUNT_SETUP] ?? [];
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
            $appDomain = $progressData['payload']['appDomain'] ?? '';
            $cookieDomain = $this->buildCookieDomain($appDomain ?: $request->getHostname());

            $response->addCookie('a_session_console', $sessionSecret, $expires, '/', $cookieDomain, $isHttps, true, $sameSite);
            $response->addCookie('a_session_console_legacy', $sessionSecret, $expires, '/', $cookieDomain, $isHttps, true, $sameSite);
            if ($sessionId) {
                $response->addHeader('X-Appwrite-Session', $sessionId);
            }
        }

        @unlink(Server::INSTALLER_CONFIG_FILE);

        $response->json(['success' => true]);
    }

    /**
     * Compute the cookie domain to match Appwrite's convention in general.php.
     *
     * For localhost and IP addresses the domain is left empty (host-only cookie).
     * For real hostnames, the domain is prefixed with a dot so the cookie matches
     * Appwrite's default `'.' . $request->getHostname()` behaviour and lives in
     * the same cookie-jar slot — preventing stale ghost cookies after logout.
     */
    private function buildCookieDomain(string $raw): string
    {
        $hostname = $this->extractHostname($raw);
        if ($hostname === '' || $hostname === 'localhost' || $hostname === '0.0.0.0' || $hostname === 'traefik') {
            return '';
        }
        if (filter_var($hostname, FILTER_VALIDATE_IP) !== false) {
            return '';
        }
        return '.' . $hostname;
    }

    /**
     * Extract the bare hostname from an appDomain value, stripping any port
     * suffix or IPv6 bracket notation.
     */
    private function extractHostname(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }
        if (str_starts_with($domain, '[')) {
            $end = strpos($domain, ']');
            return $end !== false ? substr($domain, 1, $end - 1) : '';
        }
        $parts = explode(':', $domain);
        return count($parts) <= 2 ? strtolower($parts[0]) : strtolower($domain);
    }
}
