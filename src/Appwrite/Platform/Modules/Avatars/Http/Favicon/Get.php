<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Favicon;

use Appwrite\Avatars\Favicon;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\PublicURL;
use Appwrite\Platform\Modules\Avatars\Http\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\URL\URL as URLParse;
use Appwrite\Utopia\Response;
use enshrined\svgSanitize\Sanitizer as SvgSanitizer;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Client;
use Utopia\Image\Image;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Platform\Scope\HTTP;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method as RequestMethod;
use Utopia\Psr7\Request\Factory as RequestFactory;
use Utopia\System\System;

class Get extends Action
{
    use HTTP;

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
                locationAuth: ['Project', 'ImpersonateUserId'],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::IMAGE
            ))
            ->param('url', '', fn (PublicURL $publicURL) => $publicURL, 'Website URL which you want to fetch the favicon from.', false, ['publicURL'])
            ->inject('response')
            ->inject('publicURL')
            ->inject('clientForAvatars')
            ->callback($this->action(...));
    }

    public function action(string $url, Response $response, PublicURL $publicURL, Client $clientForAvatars)
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

        $client = $clientForAvatars->withTimeout(15);

        $pageUrl = $url;

        try {
            $pageResponse = $this->safeFetch($url, $userAgent, $publicURL, $client, $pageUrl);
        } catch (\Throwable) {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
        }

        [$outputHref, $outputExt] = Favicon::locate((string) $pageResponse->getBody(), $pageUrl);

        try {
            $iconResponse = $this->safeFetch($outputHref, $userAgent, $publicURL, $client);
        } catch (\Throwable) {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
        }

        if ($iconResponse->getStatusCode() !== 200) {
            throw new Exception(Exception::AVATAR_ICON_NOT_FOUND);
        }

        $data = (string) $iconResponse->getBody();

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
     * Follows redirects one hop at a time so every target passes the validator (scheme,
     * known public domain, allowed addresses) before it is requested; the client then
     * checks the address it actually connects to. The last requested URL is written to
     * $finalUrl.
     *
     * @throws Exception
     */
    protected function safeFetch(string $url, string $userAgent, PublicURL $validator, ClientInterface $client, ?string &$finalUrl = null): ResponseInterface
    {
        $requestFactory = new RequestFactory();

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (!$validator->isValid($url)) {
                throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED, $validator->getDescription());
            }

            $finalUrl = $url;

            $response = $client->sendRequest(
                $requestFactory
                    ->createRequest(RequestMethod::GET, $url)
                    ->withHeader(Header::USER_AGENT, $userAgent),
            );

            $status = $response->getStatusCode();
            if ($status < 300 || $status >= 400) {
                return $response;
            }

            $locations = $response->getHeader(Header::LOCATION);
            $location = \end($locations) ?: '';
            if ($location === '') {
                return $response;
            }

            $url = URLParse::resolveLocation($url, $location);
        }

        throw new \RuntimeException('Too many redirects.');
    }
}
