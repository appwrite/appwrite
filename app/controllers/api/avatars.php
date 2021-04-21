<?php

use Appwrite\URL\URL as URLParse;
use Appwrite\Utopia\Response;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Utopia\App;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Exception;
use Utopia\Image\Image;
use Utopia\Validator\Boolean;
use Utopia\Validator\HexColor;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Appwrite\Network\Validator\URL;
use Utopia\Validator\WhiteList;

$avatarCallback = function ($type, $code, $width, $height, $quality, $response) {
    /** @var Appwrite\Utopia\Response $response */

    $code = \strtolower($code);
    $type = \strtolower($type);
    $set = Config::getParam('avatar-' . $type, []);

    if (empty($set)) {
        throw new Exception('Avatar set not found', 404);
    }

    if (!\array_key_exists($code, $set)) {
        throw new Exception('Avatar not found', 404);
    }

    if (!\extension_loaded('imagick')) {
        throw new Exception('Imagick extension is missing', 500);
    }

    $output = 'png';
    $date = \date('D, d M Y H:i:s', \time() + (60 * 60 * 24 * 45)) . ' GMT'; // 45 days cache
    $key = \md5('/v1/avatars/:type/:code-' . $code . $width . $height . $quality . $output);
    $path = $set[$code];
    $type = 'png';

    if (!\is_readable($path)) {
        throw new Exception('File not readable in ' . $path, 500);
    }

    $cache = new Cache(new Filesystem(APP_STORAGE_CACHE . '/app-0')); // Limit file number or size
    $data = $cache->load($key, 60 * 60 * 24 * 30 * 3/* 3 months */);

    if ($data) {
        //$output = (empty($output)) ? $type : $output;

        return $response
            ->setContentType('image/png')
            ->addHeader('Expires', $date)
            ->addHeader('X-Appwrite-Cache', 'hit')
            ->send($data)
        ;
    }

    $image = new Image(\file_get_contents($path));

    $image->crop((int) $width, (int) $height);

    $output = (empty($output)) ? $type : $output;

    $data = $image->output($output, $quality);

    $cache->save($key, $data);

    $response
        ->setContentType('image/png')
        ->addHeader('Expires', $date)
        ->addHeader('X-Appwrite-Cache', 'miss')
        ->send($data, null);

    unset($image);
};

App::get('/v1/avatars/credit-cards/:code')
    ->desc('Get Credit Card Icon')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'avatars')
    ->label('sdk.method', 'getCreditCard')
    ->label('sdk.methodType', 'location')
    ->label('sdk.description', '/docs/references/avatars/get-credit-card.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_IMAGE_PNG)
    ->param('code', '', new WhiteList(\array_keys(Config::getParam('avatar-credit-cards'))), 'Credit Card Code. Possible values: ' . \implode(', ', \array_keys(Config::getParam('avatar-credit-cards'))) . '.')
    ->param('width', 100, new Range(0, 2000), 'Image width. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('height', 100, new Range(0, 2000), 'Image height. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('quality', 100, new Range(0, 100), 'Image quality. Pass an integer between 0 to 100. Defaults to 100.', true)
    ->inject('response')
    ->action(function ($code, $width, $height, $quality, $response) use ($avatarCallback) {
        return $avatarCallback('credit-cards', $code, $width, $height, $quality, $response);
    });

App::get('/v1/avatars/browsers/:code')
    ->desc('Get Browser Icon')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'avatars')
    ->label('sdk.method', 'getBrowser')
    ->label('sdk.methodType', 'location')
    ->label('sdk.description', '/docs/references/avatars/get-browser.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_IMAGE_PNG)
    ->param('code', '', new WhiteList(\array_keys(Config::getParam('avatar-browsers'))), 'Browser Code.')
    ->param('width', 100, new Range(0, 2000), 'Image width. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('height', 100, new Range(0, 2000), 'Image height. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('quality', 100, new Range(0, 100), 'Image quality. Pass an integer between 0 to 100. Defaults to 100.', true)
    ->inject('response')
    ->action(function ($code, $width, $height, $quality, $response) use ($avatarCallback) {
        return $avatarCallback('browsers', $code, $width, $height, $quality, $response);
    });

App::get('/v1/avatars/flags/:code')
    ->desc('Get Country Flag')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'avatars')
    ->label('sdk.method', 'getFlag')
    ->label('sdk.methodType', 'location')
    ->label('sdk.description', '/docs/references/avatars/get-flag.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_IMAGE_PNG)
    ->param('code', '', new WhiteList(\array_keys(Config::getParam('avatar-flags'))), 'Country Code. ISO Alpha-2 country code format.')
    ->param('width', 100, new Range(0, 2000), 'Image width. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('height', 100, new Range(0, 2000), 'Image height. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('quality', 100, new Range(0, 100), 'Image quality. Pass an integer between 0 to 100. Defaults to 100.', true)
    ->inject('response')
    ->action(function ($code, $width, $height, $quality, $response) use ($avatarCallback) {
        return $avatarCallback('flags', $code, $width, $height, $quality, $response);
    });

App::get('/v1/avatars/image')
    ->desc('Get Image from URL')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'avatars')
    ->label('sdk.method', 'getImage')
    ->label('sdk.methodType', 'location')
    ->label('sdk.description', '/docs/references/avatars/get-image.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_IMAGE)
    ->param('url', '', new URL(), 'Image URL which you want to crop.')
    ->param('width', 400, new Range(0, 2000), 'Resize preview image width, Pass an integer between 0 to 2000.', true)
    ->param('height', 400, new Range(0, 2000), 'Resize preview image height, Pass an integer between 0 to 2000.', true)
    ->inject('response')
    ->action(function ($url, $width, $height, $response) {
        /** @var Appwrite\Utopia\Response $response */

        $quality = 80;
        $output = 'png';
        $date = \date('D, d M Y H:i:s', \time() + (60 * 60 * 24 * 45)) . ' GMT'; // 45 days cache
        $key = \md5('/v2/avatars/images-' . $url . '-' . $width . '/' . $height . '/' . $quality);
        $type = 'png';
        $cache = new Cache(new Filesystem(APP_STORAGE_CACHE . '/app-0')); // Limit file number or size
        $data = $cache->load($key, 60 * 60 * 24 * 7/* 1 week */);

        if ($data) {
            return $response
                ->setContentType('image/png')
                ->addHeader('Expires', $date)
                ->addHeader('X-Appwrite-Cache', 'hit')
                ->send($data)
            ;
        }

        if (!\extension_loaded('imagick')) {
            throw new Exception('Imagick extension is missing', 500);
        }

        $fetch = @\file_get_contents($url, false);

        if (!$fetch) {
            throw new Exception('Image not found', 404);
        }

        try {
            $image = new Image($fetch);
        } catch (\Exception$exception) {
            throw new Exception('Unable to parse image', 500);
        }

        $image->crop((int) $width, (int) $height);

        $output = (empty($output)) ? $type : $output;

        $data = $image->output($output, $quality);

        $cache->save($key, $data);

        $response
            ->setContentType('image/png')
            ->addHeader('Expires', $date)
            ->addHeader('X-Appwrite-Cache', 'miss')
            ->send($data);
        ;

        unset($image);
    });

App::get('/v1/avatars/favicon')
    ->desc('Get Favicon')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'avatars')
    ->label('sdk.method', 'getFavicon')
    ->label('sdk.methodType', 'location')
    ->label('sdk.description', '/docs/references/avatars/get-favicon.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_IMAGE)
    ->param('url', '', new URL(), 'Website URL which you want to fetch the favicon from.')
    ->inject('response')
    ->action(function ($url, $response) {
        /** @var Appwrite\Utopia\Response $response */

        $width = 56;
        $height = 56;
        $quality = 80;
        $output = 'png';
        $date = \date('D, d M Y H:i:s', \time() + (60 * 60 * 24 * 45)) . ' GMT'; // 45 days cache
        $key = \md5('/v2/avatars/favicon-' . $url);
        $type = 'png';
        $cache = new Cache(new Filesystem(APP_STORAGE_CACHE . '/app-0')); // Limit file number or size
        $data = $cache->load($key, 60 * 60 * 24 * 30 * 3/* 3 months */);

        if ($data) {
            return $response
                ->setContentType('image/png')
                ->addHeader('Expires', $date)
                ->addHeader('X-Appwrite-Cache', 'hit')
                ->send($data)
            ;
        }

        if (!\extension_loaded('imagick')) {
            throw new Exception('Imagick extension is missing', 500);
        }

        $curl = \curl_init();

        \curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_URL => $url,
            CURLOPT_USERAGENT => \sprintf(APP_USERAGENT,
                App::getEnv('_APP_VERSION', 'UNKNOWN'),
                App::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS', APP_EMAIL_SECURITY)
            ),
        ]);

        $html = \curl_exec($curl);

        \curl_close($curl);

        if (!$html) {
            throw new Exception('Failed to fetch remote URL', 404);
        }

        $doc = new DOMDocument();
        $doc->strictErrorChecking = false;
        @$doc->loadHTML($html);

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
                    //case 'apple-touch-icon':
                    $ext = \pathinfo(\parse_url($absolute, PHP_URL_PATH), PATHINFO_EXTENSION);

                    switch ($ext) {
                        case 'ico':
                        case 'png':
                        case 'jpg':
                        case 'jpeg':
                            $size = \explode('x', \strtolower($sizes));

                            $sizeWidth = (int) $size[0] ?? 0;
                            $sizeHeight = (int) $size[1] ?? 0;

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

        if ('ico' == $outputExt) { // Skip crop, Imagick isn\'t supporting icon files
            $data = @\file_get_contents($outputHref, false);

            if (empty($data) || (\mb_substr($data, 0, 5) === '<html') || \mb_substr($data, 0, 5) === '<!doc') {
                throw new Exception('Favicon not found', 404);
            }

            $cache->save($key, $data);

            return $response
                ->setContentType('image/x-icon')
                ->addHeader('Expires', $date)
                ->addHeader('X-Appwrite-Cache', 'miss')
                ->send($data)
            ;
        }

        $fetch = @\file_get_contents($outputHref, false);

        if (!$fetch) {
            throw new Exception('Icon not found', 404);
        }

        $image = new Image($fetch);

        $image->crop((int) $width, (int) $height);

        $output = (empty($output)) ? $type : $output;

        $data = $image->output($output, $quality);

        $cache->save($key, $data);

        $response
            ->setContentType('image/png')
            ->addHeader('Expires', $date)
            ->addHeader('X-Appwrite-Cache', 'miss')
            ->send($data);

        unset($image);
    });

App::get('/v1/avatars/qr')
    ->desc('Get QR Code')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'avatars')
    ->label('sdk.method', 'getQR')
    ->label('sdk.methodType', 'location')
    ->label('sdk.description', '/docs/references/avatars/get-qr.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_IMAGE_PNG)
    ->param('text', '', new Text(512), 'Plain text to be converted to QR code image.')
    ->param('size', 400, new Range(0, 1000), 'QR code size. Pass an integer between 0 to 1000. Defaults to 400.', true)
    ->param('margin', 1, new Range(0, 10), 'Margin from edge. Pass an integer between 0 to 10. Defaults to 1.', true)
    ->param('download', false, new Boolean(true), 'Return resulting image with \'Content-Disposition: attachment \' headers for the browser to start downloading it. Pass 0 for no header, or 1 for otherwise. Default value is set to 0.', true)
    ->inject('response')
    ->action(function ($text, $size, $margin, $download, $response) {
        /** @var Appwrite\Utopia\Response $response */

        $download = ($download === '1' || $download === 'true' || $download === 1 || $download === true);
        $options = new QROptions([
            'addQuietzone' => true,
            'quietzoneSize' => $margin,
            'outputType' => QRCode::OUTPUT_IMAGICK,
        ]);

        $qrcode = new QRCode($options);

        if ($download) {
            $response->addHeader('Content-Disposition', 'attachment; filename="qr.png"');
        }

        $image = new Image($qrcode->render($text));

        $image->crop((int) $size, (int) $size);

        $response
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + (60 * 60 * 24 * 45)) . ' GMT') // 45 days cache
            ->setContentType('image/png')
            ->send($image->output('png', 9))
        ;
    });

App::get('/v1/avatars/initials')
    ->desc('Get User Initials')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'avatars')
    ->label('sdk.method', 'getInitials')
    ->label('sdk.methodType', 'location')
    ->label('sdk.description', '/docs/references/avatars/get-initials.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_IMAGE_PNG)
    ->param('name', '', new Text(128), 'Full Name. When empty, current user name or email will be used. Max length: 128 chars.', true)
    ->param('width', 500, new Range(0, 2000), 'Image width. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('height', 500, new Range(0, 2000), 'Image height. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('color', '', new HexColor(), 'Changes text color. By default a random color will be picked and stay will persistent to the given name.', true)
    ->param('background', '', new HexColor(), 'Changes background color. By default a random color will be picked and stay will persistent to the given name.', true)
    ->inject('response')
    ->inject('user')
    ->action(function ($name, $width, $height, $color, $background, $response, $user) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $user */

        $themes = [
            ['color' => '#27005e', 'background' => '#e1d2f6'], // VIOLET
            ['color' => '#5e2700', 'background' => '#f3d9c6'], // ORANGE
            ['color' => '#006128', 'background' => '#c9f3c6'], // GREEN
            ['color' => '#580061', 'background' => '#f2d1f5'], // FUSCHIA
            ['color' => '#00365d', 'background' => '#c6e1f3'], // BLUE
            ['color' => '#00075c', 'background' => '#d2d5f6'], // INDIGO
            ['color' => '#610038', 'background' => '#f5d1e6'], // PINK
            ['color' => '#386100', 'background' => '#dcf1bd'], // LIME
            ['color' => '#615800', 'background' => '#f1ecba'], // YELLOW
            ['color' => '#610008', 'background' => '#f6d2d5'], // RED
        ];

        $rand = \rand(0, \count($themes) - 1);

        $name = (!empty($name)) ? $name : $user->getAttribute('name', $user->getAttribute('email', ''));
        $words = \explode(' ', \strtoupper($name));
        $initials = null;
        $code = 0;

        foreach ($words as $key => $w) {
            $initials .= $w[0] ?? '';
            $code += (isset($w[0])) ? \ord($w[0]) : 0;

            if ($key == 1) {
                break;
            }
        }

        $length = \count($words);
        $rand = \substr($code, -1);
        $background = (!empty($background)) ? '#' . $background : $themes[$rand]['background'];
        $color = (!empty($color)) ? '#' . $color : $themes[$rand]['color'];

        $image = new \Imagick();
        $draw = new \ImagickDraw();
        $fontSize = \min($width, $height) / 2;

        $draw->setFont(__DIR__ . "/../../../public/fonts/poppins-v9-latin-500.ttf");
        $image->setFont(__DIR__ . "/../../../public/fonts/poppins-v9-latin-500.ttf");

        $draw->setFillColor(new \ImagickPixel($color));
        $draw->setFontSize($fontSize);

        $draw->setTextAlignment(\Imagick::ALIGN_CENTER);
        $draw->annotation($width / 1.97, ($height / 2) + ($fontSize / 3), $initials);

        $image->newImage($width, $height, $background);
        $image->setImageFormat("png");
        $image->drawImage($draw);

        //$image->setImageCompressionQuality(9 - round(($quality / 100) * 9));

        $response
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + (60 * 60 * 24 * 45)) . ' GMT') // 45 days cache
            ->setContentType('image/png')
            ->send($image->getImageBlob())
        ;
    });
