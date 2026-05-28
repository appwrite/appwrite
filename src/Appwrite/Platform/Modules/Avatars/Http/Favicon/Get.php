<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Favicon;

use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\PublicHostname;
use Appwrite\Platform\Modules\Avatars\Http\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\URL\URL as URLParse;
use Appwrite\Utopia\Response;
use DOMDocument;
use DOMElement;
use enshrined\svgSanitize\Sanitizer as SvgSanitizer;
use Utopia\Domains\Domain;
use Utopia\Fetch\Client;
use Utopia\Fetch\Response as FetchResponse;
use Utopia\Image\Image;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\URL;

class Get extends Action
{
    use HTTP;

    private const ALLOWED_SCHEMES = ['http', 'https'];
    private const MAX_REDIRECTS = 5;

    public static function getName(): string
    {
        return 'getFavicon';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(UtopiaAction::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/avatars/favicon')
            ->desc('Get favicon')
            ->groups(['api', 'avatars'])
            ->label('scope', 'avatars.read')
            ->label('cache', true)
            ->label('cache.resource', 'avatar/favicon')
            ->label('sdk', new Method(
                namespace: 'avatars',
                group: null,
                name: 'getFavicon',
                description: '/docs/references/avatars/get-favicon.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                type: MethodType::LOCATION,
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::IMAGE
            ))
            ->param('url', '', new URL(self::ALLOWED_SCHEMES), 'Website URL which you want to fetch the favicon from.')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $url, Response $response)
    {
        $width = 56;
        $height = 56;
        $quality = 80;
        $output = 'png';

        if (!\extension_loaded('imagick')) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Imagick extension is missing');
        }

        $userAgent = \sprintf(
            APP_USERAGENT,
            System::getEnv('_APP_VERSION', 'UNKNOWN'),
            System::getEnv('_APP_EMAIL_SECURITY', System::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS', APP_EMAIL_SECURITY))
        );

        $pageResponse = $this->safeFetch($url, $userAgent);
        $body = $pageResponse->getBody();

        $doc = new DOMDocument();
        $doc->strictErrorChecking = false;
        if (!empty($body)) {
            @$doc->loadHTML($body);
        }

        $links = $doc->getElementsByTagName('link');
        $outputHref = '';
        $outputExt = '';
        $space = 0;

        foreach ($links as $link) { /* @var $link DOMElement */
            $href = $link->getAttribute('href');
            $rel = $link->getAttribute('rel');
            $sizes = $link->getAttribute('sizes');
            $absolute = URLParse::unparse(\array_merge(\parse_url($url), \parse_url($href)));

            switch (\strtolower($rel)) {
                case 'icon':
                case 'shortcut icon':
                    $ext = \pathinfo(\parse_url($absolute, PHP_URL_PATH), PATHINFO_EXTENSION);

                    switch ($ext) {
                        case 'svg':
                            // SVG icons are prioritized by assigning the maximum possible value.
                            $space = PHP_INT_MAX;
                            $outputHref = $absolute;
                            $outputExt = $ext;
                            break;
                        case 'ico':
                        case 'png':
                        case 'jpg':
                        case 'jpeg':
                            $size = \explode('x', \strtolower($sizes));

                            $sizeWidth = (int) $size[0];
                            $sizeHeight = (int) ($size[1] ?? 0);

                            if (($sizeWidth * $sizeHeight) >= $space) {
                                $space = $sizeWidth * $sizeHeight;
                                $outputHref = $absolute;
                                $outputExt = $ext;
                            }

                            break;
                    }

                    break;
            }
        }

        if (empty($outputHref) || empty($outputExt)) {
            $default = \parse_url($url);

            $outputHref = $default['scheme'] . '://' . $default['host'] . '/favicon.ico';
            $outputExt = 'ico';
        }

        $iconResponse = $this->safeFetch($outputHref, $userAgent);

        if ($iconResponse->getStatusCode() !== 200) {
            throw new Exception(Exception::AVATAR_ICON_NOT_FOUND);
        }

        $data = $iconResponse->getBody();

        if ('ico' === $outputExt) { // Skip crop, Imagick isn\'t supporting icon files
            if (
                empty($data) ||
                stripos($data, '<html') === 0 ||
                stripos($data, '<!doc') === 0
            ) {
                throw new Exception(Exception::AVATAR_ICON_NOT_FOUND, 'Favicon not found');
            }
            $response
                ->addHeader('Cache-Control', 'private, max-age=2592000') // 30 days
                ->setContentType('image/x-icon')
                ->file($data);
            return;
        }

        if ('svg' === $outputExt) { // Skip crop, Imagick isn\'t supporting svg files
            $sanitizer = new SvgSanitizer();
            $sanitizer->minify(true);
            $cleanSvg = $sanitizer->sanitize($data);
            if ($cleanSvg === false) {
                throw new Exception(Exception::AVATAR_SVG_SANITIZATION_FAILED);
            }
            $response
                ->addHeader('Cache-Control', 'private, max-age=2592000') // 30 days
                ->setContentType('image/svg+xml')
                ->file($cleanSvg);
            return;
        }

        $image = new Image($data);
        $image->crop((int) $width, (int) $height);
        $data = $image->output($output, $quality);

        $response
            ->addHeader('Cache-Control', 'private, max-age=2592000') // 30 days
            ->setContentType('image/png')
            ->file($data);
        unset($image);
    }

    /**
     * Fetches a URL with manual redirect handling.
     *
     * Every URL in the redirect chain — the initial request and each Location
     * target — is re-validated for scheme, public-suffix membership, and
     * publicly-routable resolution. This is what prevents an attacker-controlled
     * page from bouncing the request to an internal IP such as 169.254.169.254.
     */
    private function safeFetch(string $url, string $userAgent): FetchResponse
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $this->assertUrlSafe($url);

            try {
                $response = (new Client())
                    ->setAllowRedirects(false)
                    ->setUserAgent($userAgent)
                    ->fetch($url);
            } catch (\Throwable) {
                throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
            }

            $status = $response->getStatusCode();
            if ($status < 300 || $status >= 400) {
                return $response;
            }

            $location = $response->getHeaders()['location'] ?? '';
            if ($location === '') {
                return $response;
            }

            $url = self::resolveLocation($url, $location);
        }

        throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
    }

    private function assertUrlSafe(string $url): void
    {
        $parts = \parse_url($url);

        $scheme = \strtolower($parts['scheme'] ?? '');
        if (!\in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
        }

        // PSL check rejects bare hostnames, made-up TLDs, and pure IP literals
        // before we spend a DNS lookup on them.
        $isIpLiteral = \filter_var(\trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
        if (!$isIpLiteral) {
            try {
                $domain = new Domain($host);
            } catch (\Throwable) {
                throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
            }

            if (!$domain->isKnown()) {
                throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
            }
        }

        if (!(new PublicHostname())->isValid($host)) {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
        }
    }

    private static function resolveLocation(string $base, string $location): string
    {
        $location = \trim($location);

        if (\preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }

        $baseParts = \parse_url($base);
        $scheme = $baseParts['scheme'] ?? 'http';
        $host = $baseParts['host'] ?? '';
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';

        if (\str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        if (\str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $port . $location;
        }

        $path = $baseParts['path'] ?? '/';
        $slash = \strrpos($path, '/');
        $path = $slash === false ? '/' : \substr($path, 0, $slash + 1);

        return $scheme . '://' . $host . $port . $path . $location;
    }
}
