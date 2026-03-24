<?php

namespace Appwrite\Platform\Installer\Http\Installer\Certificate;

use Appwrite\Platform\Installer\Validator\AppDomain;
use Utopia\Http\Adapter\Swoole\Response;
use Utopia\Platform\Action;
use Utopia\Validator\Range;

class Get extends Action
{
    private const int CONNECTION_TIMEOUT_SECONDS = 5;

    public static function getName(): string
    {
        return 'installerCertificateGet';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/install/certificate')
            ->desc('Check if SSL certificate is ready for a domain')
            ->param('domain', '', new AppDomain(), 'Domain to check')
            ->param('port', 443, new Range(1, 65535), 'HTTPS port to check', true)
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $domain, int $port, Response $response): void
    {
        $domain = trim($domain);
        if ($domain === '') {
            $response->json(['ready' => false]);
            return;
        }

        $ready = $this->checkHttps($domain, $port);
        $response->json(['ready' => $ready]);
    }

    private function checkHttps(string $domain, int $port): bool
    {
        $gateway = $this->getDockerGateway();

        $ch = curl_init();
        $options = [
            CURLOPT_URL => 'https://' . $domain . ':' . $port . '/',
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECTION_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::CONNECTION_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($gateway !== '') {
            $options[CURLOPT_RESOLVE] = [$domain . ':' . $port . ':' . $gateway];
        }

        curl_setopt_array($ch, $options);
        curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        return $errno === 0;
    }

    private function getDockerGateway(): string
    {
        $route = @file_get_contents('/proc/net/route');
        if ($route === false) {
            return '';
        }

        foreach (explode("\n", $route) as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if (isset($fields[1]) && $fields[1] === '00000000' && isset($fields[2])) {
                $hex = $fields[2];
                if (strlen($hex) !== 8) {
                    continue;
                }
                $ip = long2ip((int) hexdec($hex[6] . $hex[7] . $hex[4] . $hex[5] . $hex[2] . $hex[3] . $hex[0] . $hex[1]));
                return $ip;
            }
        }

        return '';
    }
}
