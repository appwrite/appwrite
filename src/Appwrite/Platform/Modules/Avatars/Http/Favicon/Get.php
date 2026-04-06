<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Favicon;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Avatars\Http\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\URL\URL as URLParse;
use Appwrite\Utopia\Response;
use enshrined\svgSanitize\Sanitizer as SvgSanitizer;
use Utopia\Domains\Domain;
use Utopia\Fetch\Client;
use Utopia\Image\Image;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\URL;

class Get extends Action
{
    use HTTP;

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
            ->param('url', '', new URL(['http', 'https']), 'Website URL which you want to fetch the favicon from.')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $url, Response $response)
    {
        $width = 56;
        $height = 56;
        $quality = 80;
        $output = 'png';
        $type = 'png';

        if (!\extension_loaded('imagick')) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Imagick extension is missing');
        }

        $domain = new Domain(\parse_url($url, PHP_URL_HOST));

        if (!$domain->isKnown()) {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
        }

        $client = new Client();
        try {
            $res = $client
                ->setAllowRedirects(true)
                ->setMaxRedirects(5)
                ->setUserAgent(\sprintf(
                    APP_USERAGENT,
                    System::getEnv('_APP_VERSION', 'UNKNOWN'),
                    System::getEnv('_APP_EMAIL_SECURITY', System::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS', APP_EMAIL_SECURITY))
                ))
                ->fetch($url);
        } catch (\Throwable) {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
        }

        $outputHref = '';
        $outputExt = '';
        $space = 0;

        $linkTags = [];
        $html = \preg_replace('/<!--.*?-->/s', '', $res->getBody()) ?? $res->getBody();
        \preg_match_all('/<link\b[^>]*>/i', $html, $linkTags);

        foreach ($linkTags[0] ?? [] as $tag) {
            $attributes = [];

            // Match HTML attributes in double quotes, single quotes, or bare form.
            \preg_match_all('/([a-zA-Z:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/s', $tag, $attributeMatches, \PREG_SET_ORDER);

            foreach ($attributeMatches as $attributeMatch) {
                $attributes[\strtolower($attributeMatch[1])] = \html_entity_decode(
                    $attributeMatch[2] !== '' ? $attributeMatch[2] : ($attributeMatch[3] !== '' ? $attributeMatch[3] : $attributeMatch[4]),
                    \ENT_QUOTES | \ENT_HTML5
                );
            }

            $href = $attributes['href'] ?? '';
            $rel = \strtolower(\preg_replace('/\s+/', ' ', \trim($attributes['rel'] ?? '')));
            $sizes = $attributes['sizes'] ?? '';

            if (empty($href) || !\in_array($rel, ['icon', 'shortcut icon'], true)) {
                continue;
            }

            $absolute = URLParse::unparse(\array_merge(\parse_url($url), \parse_url($href)));

            $ext = \pathinfo(\parse_url($absolute, PHP_URL_PATH), PATHINFO_EXTENSION);

            switch ($ext) {
                case 'svg':
                    $space = PHP_INT_MAX;
                    $outputHref = $absolute;
                    $outputExt = $ext;
                    break;
                case 'ico':
                case 'png':
                case 'jpg':
                case 'jpeg':
                    $size = \explode('x', \strtolower($sizes));

                    $sizeWidth = (int) ($size[0] ?? 0);
                    $sizeHeight = (int) ($size[1] ?? 0);

                    if (($sizeWidth * $sizeHeight) >= $space) {
                        $space = $sizeWidth * $sizeHeight;
                        $outputHref = $absolute;
                        $outputExt = $ext;
                    }

                    break;
            }
        }

        if (empty($outputHref) || empty($outputExt)) {
            $default = \parse_url($url);

            $outputHref = $default['scheme'] . '://' . $default['host'] . '/favicon.ico';
            $outputExt = 'ico';
        }

        $domain = new Domain(\parse_url($outputHref, PHP_URL_HOST));

        if (!$domain->isKnown()) {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
        }

        $client = new Client();
        try {
            $res = $client
                ->setAllowRedirects(true)
                ->setMaxRedirects(5)
                ->fetch($outputHref);
        } catch (\Throwable) {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
        }

        if ($res->getStatusCode() !== 200) {
            throw new Exception(Exception::AVATAR_ICON_NOT_FOUND);
        }

        $data = $res->getBody();

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
}
