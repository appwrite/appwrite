<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Screenshots;

use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Avatars\Http\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Domains\Domain;
use Utopia\Fetch\Client;
use Utopia\Image\Image;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Boolean;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getScreenshot';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(UtopiaAction::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/avatars/screenshots')
            ->desc('Get webpage screenshot')
            ->groups(['api', 'avatars'])
            ->label('scope', 'avatars.read')
            ->label('usage.metric', METRIC_AVATARS_SCREENSHOTS_GENERATED)
            ->label('abuse-limit', 60)
            ->label('cache', true)
            ->label('cache.resourceType', 'avatar/screenshot')
            ->label('cache.resource', 'screenshot/{request.url}/{request.width}/{request.height}/{request.scale}/{request.theme}/{request.userAgent}/{request.fullpage}/{request.locale}/{request.timezone}/{request.latitude}/{request.longitude}/{request.accuracy}/{request.touch}/{request.permissions}/{request.sleep}/{request.quality}/{request.output}')
            ->label('sdk', new Method(
                namespace: 'avatars',
                group: null,
                name: 'getScreenshot',
                description: '/docs/references/avatars/get-screenshot.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                type: MethodType::LOCATION,
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::IMAGE_PNG
            ))
            ->param('url', '', new URL(['http', 'https']), 'Website URL which you want to capture.', example: 'https://example.com')
            ->param('headers', [], new Assoc(), 'HTTP headers to send with the browser request. Defaults to empty.', true, example: '{"Authorization":"Bearer token123","X-Custom-Header":"value"}')
            ->param('viewportWidth', 1280, new Range(1, 1920), 'Browser viewport width. Pass an integer between 1 to 1920. Defaults to 1280.', true, example: '1920')
            ->param('viewportHeight', 720, new Range(1, 1080), 'Browser viewport height. Pass an integer between 1 to 1080. Defaults to 720.', true, example: '1080')
            ->param('scale', 1, new Range(0.1, 3, Range::TYPE_FLOAT), 'Browser scale factor. Pass a number between 0.1 to 3. Defaults to 1.', true, example: '2')
            ->param('theme', 'light', new WhiteList(['light', 'dark']), 'Browser theme. Pass "light" or "dark". Defaults to "light".', true, example: 'dark')
            ->param('userAgent', '', new Text(512), 'Custom user agent string. Defaults to browser default.', true, example: 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15')
            ->param('fullpage', false, new Boolean(true), 'Capture full page scroll. Pass 0 for viewport only, or 1 for full page. Defaults to 0.', true, example: 'true')
            ->param('locale', '', new Text(10), 'Browser locale (e.g., "en-US", "fr-FR"). Defaults to browser default.', true, example: 'en-US')
            ->param('timezone', '', new WhiteList(timezone_identifiers_list()), 'IANA timezone identifier (e.g., "America/New_York", "Europe/London"). Defaults to browser default.', true, example: 'america/new_york')
            ->param('latitude', 0, new Range(-90, 90, Range::TYPE_FLOAT), 'Geolocation latitude. Pass a number between -90 to 90. Defaults to 0.', true, example: '37.7749')
            ->param('longitude', 0, new Range(-180, 180, Range::TYPE_FLOAT), 'Geolocation longitude. Pass a number between -180 to 180. Defaults to 0.', true, example: '-122.4194')
            ->param('accuracy', 0, new Range(0, 100000, Range::TYPE_FLOAT), 'Geolocation accuracy in meters. Pass a number between 0 to 100000. Defaults to 0.', true, example: '100')
            ->param('touch', false, new Boolean(true), 'Enable touch support. Pass 0 for no touch, or 1 for touch enabled. Defaults to 0.', true, example: 'true')
            ->param('permissions', [], new ArrayList(new WhiteList(['geolocation', 'camera', 'microphone', 'notifications', 'midi', 'push', 'clipboard-read', 'clipboard-write', 'payment-handler', 'usb', 'bluetooth', 'accelerometer', 'gyroscope', 'magnetometer', 'ambient-light-sensor', 'background-sync', 'persistent-storage', 'screen-wake-lock', 'web-share', 'xr-spatial-tracking'])), 'Browser permissions to grant. Pass an array of permission names like ["geolocation", "camera", "microphone"]. Defaults to empty.', true, example: '["geolocation","notifications"]')
            ->param('sleep', 0, new Range(0, 10), 'Wait time in seconds before taking the screenshot. Pass an integer between 0 to 10. Defaults to 0.', true, example: '3')
            ->param('width', 0, new Range(0, 2000), 'Output image width. Pass 0 to use original width, or an integer between 1 to 2000. Defaults to 0 (original width).', true, example: '800')
            ->param('height', 0, new Range(0, 2000), 'Output image height. Pass 0 to use original height, or an integer between 1 to 2000. Defaults to 0 (original height).', true, example: '600')
            ->param('quality', -1, new Range(-1, 100), 'Screenshot quality. Pass an integer between 0 to 100. Defaults to keep existing image quality.', true, example: '85')
            ->param('output', '', new WhiteList(\array_keys(Config::getParam('storage-outputs')), true), 'Output format type (jpeg, jpg, png, gif and webp).', true, example: 'jpeg')
            ->inject('response')
            ->inject('queueForStatsUsage')
            ->callback($this->action(...));
    }

    public function action(string $url, array $headers, int $viewportWidth, int $viewportHeight, float $scale, string $theme, string $userAgent, bool $fullpage, string $locale, string $timezone, float $latitude, float $longitude, float $accuracy, bool $touch, array $permissions, int $sleep, int $width, int $height, int $quality, string $output, Response $response, StatsUsage $queueForStatsUsage)
    {
        if (!\extension_loaded('imagick')) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Imagick extension is missing');
        }

        $domain = new Domain(\parse_url($url, PHP_URL_HOST));

        if (!$domain->isKnown()) {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
        }

        $client = new Client();
        $client->setTimeout(30 * 1000); // 30 seconds
        $client->addHeader('content-type', Client::CONTENT_TYPE_APPLICATION_JSON);

        // Convert indexed array to empty array (should not happen due to Assoc validator)
        if (is_array($headers) && count($headers) > 0 && array_keys($headers) === range(0, count($headers) - 1)) {
            $headers = [];
        }

        // Create a new object to ensure proper JSON serialization
        $headersObject = new \stdClass();
        foreach ($headers as $key => $value) {
            $headersObject->$key = $value;
        }

        // Create the config with headers as an object
        // The custom browser service accepts: url, theme, headers, sleep, viewport, userAgent, fullPage, locale, timezoneId, geolocation, hasTouch, scale
        $config = [
            'url' => $url,
            'theme' => $theme,
            'headers' => $headersObject,
            'sleep' => $sleep * 1000, // Convert seconds to milliseconds
            'waitUntil' => 'load',
            'viewport' => [
                'width' => $viewportWidth,
                'height' => $viewportHeight
            ]
        ];

        // Add scale if not default
        if ($scale != 1) {
            $config['deviceScaleFactor'] = $scale;
        }

        // Add optional parameters that were set, preserving arrays as arrays
        if (!empty($userAgent)) {
            $config['userAgent'] = $userAgent;
        }

        if ($fullpage) {
            $config['fullPage'] = true;
        }

        if (!empty($locale)) {
            $config['locale'] = $locale;
        }

        if (!empty($timezone)) {
            $config['timezoneId'] = $timezone;
        }

        // Add geolocation if any coordinates are provided
        if ($latitude != 0 || $longitude != 0) {
            $config['geolocation'] = [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy' => $accuracy
            ];
        }

        if ($touch) {
            $config['hasTouch'] = true;
        }

        // Add permissions if provided (preserve as array)
        if (!empty($permissions)) {
            $config['permissions'] = $permissions; // Keep as array
        }

        try {
            $browserEndpoint = System::getEnv('_APP_BROWSER_HOST', 'http://appwrite-browser:3000/v1');

            $fetchResponse = $client->fetch(
                url: $browserEndpoint . '/screenshots',
                method: 'POST',
                body: $config
            );

            if ($fetchResponse->getStatusCode() >= 400) {
                throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED, 'Screenshot service failed: ' . $fetchResponse->getBody());
            }

            $screenshot = $fetchResponse->getBody();

            if (empty($screenshot)) {
                throw new Exception(Exception::AVATAR_IMAGE_NOT_FOUND, 'Screenshot not generated');
            }

            // Determine if image processing is needed
            $needsProcessing = ($width > 0 || $height > 0) || $quality !== -1 || !empty($output);

            if ($needsProcessing) {
                // Process image with cropping, quality adjustment, or format conversion
                $image = new Image($screenshot);

                $image->crop($width, $height);

                $output = $output ?: 'png'; // Default to PNG if not specified
                $resizedScreenshot = $image->output($output, $quality);
                unset($image);
            } else {
                // Return original screenshot without processing
                $resizedScreenshot = $screenshot;
                $output = 'png'; // Screenshots are typically PNG by default
            }

            // Set content type based on output format
            $outputs = Config::getParam('storage-outputs');
            $contentType = $outputs[$output] ?? $outputs['png'];

            $queueForStatsUsage->addMetric(METRIC_AVATARS_SCREENSHOTS_GENERATED, 1);

            $response
                ->addHeader('Cache-Control', 'private, max-age=2592000') // 30 days
                ->setContentType($contentType)
                ->file($resizedScreenshot);


        } catch (\Throwable $th) {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED, 'Screenshot generation failed: ' . $th->getMessage());
        }
    }
}
