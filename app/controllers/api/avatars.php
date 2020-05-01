<?php

global $utopia, $request, $response;

use Utopia\Exception;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Range;
use Utopia\Validator\URL;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Filesystem;
use Appwrite\Resize\Resize;
use Appwrite\URL\URL as URLParse;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Utopia\Config\Config;

include_once __DIR__ . '/../shared/api.php';

$types = [
    'browsers' => include __DIR__.'/../../config/avatars/browsers.php',
    'credit-cards' => include __DIR__.'/../../config/avatars/credit-cards.php',
    'flags' => include __DIR__.'/../../config/avatars/flags.php',
];

$avatarCallback = function ($type, $code, $width, $height, $quality) use ($types, $response, $request) {
    $code = strtolower($code);
    $type = strtolower($type);

    if (!array_key_exists($type, $types)) {
        throw new Exception('Avatar set not found', 404);
    }

    if (!array_key_exists($code, $types[$type])) {
        throw new Exception('Avatar not found', 404);
    }

    if (!extension_loaded('imagick')) {
        throw new Exception('Imagick extension is missing', 500);
    }

    $output = 'png';
    $date = date('D, d M Y H:i:s', time() + (60 * 60 * 24 * 45)).' GMT';  // 45 days cache
    $key = md5('/v1/avatars/:type/:code-'.$code.$width.$height.$quality.$output);
    $path = $types[$type][$code];
    $type = 'png';

    if (!is_readable($path)) {
        throw new Exception('File not readable in '.$path, 500);
    }

    $cache = new Cache(new Filesystem(APP_STORAGE_CACHE.'/app-0')); // Limit file number or size
    $data = $cache->load($key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

    if ($data) {
        //$output = (empty($output)) ? $type : $output;

        $response
            ->setContentType('image/png')
            ->addHeader('Expires', $date)
            ->addHeader('X-Appwrite-Cache', 'hit')
            ->send($data, 0)
        ;
    }

    $resize = new Resize(file_get_contents($path));

    $resize->crop((int) $width, (int) $height);

    $output = (empty($output)) ? $type : $output;

    $response
        ->setContentType('image/png')
        ->addHeader('Expires', $date)
        ->addHeader('X-Appwrite-Cache', 'miss')
        ->send('', null)
    ;

    $data = $resize->output($output, $quality);

    $cache->save($key, $data);

    echo $data;

    unset($resize);

    exit(0);
};

$utopia->get('/v1/avatars/credit-cards/:code')
    ->desc('Get Credit Card Icon')
    ->param('code', '', function () use ($types) { return new WhiteList(array_keys($types['credit-cards'])); }, 'Credit Card Code. Possible values: '.implode(', ', array_keys($types['credit-cards'])).'.')
    ->param('width', 100, function () { return new Range(0, 2000); }, 'Image width. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('height', 100, function () { return new Range(0, 2000); }, 'Image height. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('quality', 100, function () { return new Range(0, 100); }, 'Image quality. Pass an integer between 0 to 100. Defaults to 100.', true)
    ->label('scope', 'avatars.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'avatars')
    ->label('sdk.method', 'getCreditCard')
    ->label('sdk.description', '/docs/references/avatars/get-credit-card.md')
    ->action(function ($code, $width, $height, $quality) use ($avatarCallback) { return $avatarCallback('credit-cards', $code, $width, $height, $quality);
    });

$utopia->get('/v1/avatars/browsers/:code')
    ->desc('Get Browser Icon')
    ->param('code', '', function () use ($types) { return new WhiteList(array_keys($types['browsers'])); }, 'Browser Code.')
    ->param('width', 100, function () { return new Range(0, 2000); }, 'Image width. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('height', 100, function () { return new Range(0, 2000); }, 'Image height. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('quality', 100, function () { return new Range(0, 100); }, 'Image quality. Pass an integer between 0 to 100. Defaults to 100.', true)
    ->label('scope', 'avatars.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'avatars')
    ->label('sdk.method', 'getBrowser')
    ->label('sdk.description', '/docs/references/avatars/get-browser.md')
    ->action(function ($code, $width, $height, $quality) use ($avatarCallback) { return $avatarCallback('browsers', $code, $width, $height, $quality);
    });

$utopia->get('/v1/avatars/flags/:code')
    ->desc('Get Country Flag')
    ->param('code', '', function () use ($types) { return new WhiteList(array_keys($types['flags'])); }, 'Country Code. ISO Alpha-2 country code format.')
    ->param('width', 100, function () { return new Range(0, 2000); }, 'Image width. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('height', 100, function () { return new Range(0, 2000); }, 'Image height. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('quality', 100, function () { return new Range(0, 100); }, 'Image quality. Pass an integer between 0 to 100. Defaults to 100.', true)
    ->label('scope', 'avatars.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'avatars')
    ->label('sdk.method', 'getFlag')
    ->label('sdk.description', '/docs/references/avatars/get-flag.md')
    ->action(function ($code, $width, $height, $quality) use ($avatarCallback) { return $avatarCallback('flags', $code, $width, $height, $quality);
    });

$utopia->get('/v1/avatars/image')
    ->desc('Get Image from URL')
    ->param('url', '', function () { return new URL(); }, 'Image URL which you want to crop.')
    ->param('width', 400, function () { return new Range(0, 2000); }, 'Resize preview image width, Pass an integer between 0 to 2000.', true)
    ->param('height', 400, function () { return new Range(0, 2000); }, 'Resize preview image height, Pass an integer between 0 to 2000.', true)
    ->label('scope', 'avatars.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'avatars')
    ->label('sdk.method', 'getImage')
    ->label('sdk.description', '/docs/references/avatars/get-image.md')
    ->action(
        function ($url, $width, $height) use ($response) {
            $quality = 80;
            $output = 'png';
            $date = date('D, d M Y H:i:s', time() + (60 * 60 * 24 * 45)).' GMT';  // 45 days cache
            $key = md5('/v2/avatars/images-'.$url.'-'.$width.'/'.$height.'/'.$quality);
            $type = 'png';
            $cache = new Cache(new Filesystem(APP_STORAGE_CACHE.'/app-0')); // Limit file number or size
            $data = $cache->load($key, 60 * 60 * 24 * 7 /* 1 week */);

            if ($data) {
                $response
                    ->setContentType('image/png')
                    ->addHeader('Expires', $date)
                    ->addHeader('X-Appwrite-Cache', 'hit')
                    ->send($data, 0)
                ;
            }

            if (!extension_loaded('imagick')) {
                throw new Exception('Imagick extension is missing', 500);
            }

            $fetch = @file_get_contents($url, false);

            if (!$fetch) {
                throw new Exception('Image not found', 404);
            }

            try {
                $resize = new Resize($fetch);
            } catch (\Exception $exception) {
                throw new Exception('Unable to parse image', 500);
            }

            $resize->crop((int) $width, (int) $height);

            $output = (empty($output)) ? $type : $output;

            $response
                ->setContentType('image/png')
                ->addHeader('Expires', $date)
                ->addHeader('X-Appwrite-Cache', 'miss')
                ->send('', null)
            ;

            $data = $resize->output($output, $quality);

            $cache->save($key, $data);

            echo $data;

            unset($resize);

            exit(0);
        }
    );

$utopia->get('/v1/avatars/favicon')
    ->desc('Get Favicon')
    ->param('url', '', function () { return new URL(); }, 'Website URL which you want to fetch the favicon from.')
    ->label('scope', 'avatars.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'avatars')
    ->label('sdk.method', 'getFavicon')
    ->label('sdk.description', '/docs/references/avatars/get-favicon.md')
    ->action(
        function ($url) use ($response, $request) {
            $width = 56;
            $height = 56;
            $quality = 80;
            $output = 'png';
            $date = date('D, d M Y H:i:s', time() + (60 * 60 * 24 * 45)).' GMT';  // 45 days cache
            $key = md5('/v2/avatars/favicon-'.$url);
            $type = 'png';
            $cache = new Cache(new Filesystem(APP_STORAGE_CACHE.'/app-0')); // Limit file number or size
            $data = $cache->load($key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

            if ($data) {
                $response
                    ->setContentType('image/png')
                    ->addHeader('Expires', $date)
                    ->addHeader('X-Appwrite-Cache', 'hit')
                    ->send($data, 0)
                ;
            }

            if (!extension_loaded('imagick')) {
                throw new Exception('Imagick extension is missing', 500);
            }

            $curl = curl_init();

            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_URL => $url,
                CURLOPT_USERAGENT => sprintf(APP_USERAGENT,
                    Config::getParam('version'),
                    $request->getServer('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS', APP_EMAIL_SECURITY)
                ),
            ]);

            $html = curl_exec($curl);

            curl_close($curl);

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
                $absolute = URLParse::unparse(array_merge(parse_url($url), parse_url($href)));

                switch (strtolower($rel)) {
                    case 'icon':
                    case 'shortcut icon':
                        //case 'apple-touch-icon':
                        $ext = pathinfo(parse_url($absolute, PHP_URL_PATH), PATHINFO_EXTENSION);

                        switch ($ext) {
                            case 'ico':
                            case 'png':
                            case 'jpg':
                            case 'jpeg':
                                $size = explode('x', strtolower($sizes));

                                $sizeWidth = (isset($size[0])) ? (int) $size[0] : 0;
                                $sizeHeight = (isset($size[1])) ? (int) $size[1] : 0;

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
                $default = parse_url($url);

                $outputHref = $default['scheme'].'://'.$default['host'].'/favicon.ico';
                $outputExt = 'ico';
            }

            if ('ico' == $outputExt) { // Skip crop, Imagick isn\'t supporting icon files
                $data = @file_get_contents($outputHref, false);

                if (empty($data) || (mb_substr($data, 0, 5) === '<html') || mb_substr($data, 0, 5) === '<!doc') {
                    throw new Exception('Favicon not found', 404);
                }

                $cache->save($key, $data);

                $response
                    ->setContentType('image/x-icon')
                    ->addHeader('Expires', $date)
                    ->addHeader('X-Appwrite-Cache', 'miss')
                    ->send($data, 0)
                ;
            }

            $fetch = @file_get_contents($outputHref, false);

            if (!$fetch) {
                throw new Exception('Icon not found', 404);
            }

            $resize = new Resize($fetch);

            $resize->crop((int) $width, (int) $height);

            $output = (empty($output)) ? $type : $output;

            $response
                ->setContentType('image/png')
                ->addHeader('Expires', $date)
                ->addHeader('X-Appwrite-Cache', 'miss')
                ->send('', null)
            ;

            $data = $resize->output($output, $quality);

            $cache->save($key, $data);

            echo $data;

            unset($resize);

            exit(0);
        }
    );

$utopia->get('/v1/avatars/qr')
    ->desc('Get QR Code')
    ->param('text', '', function () { return new Text(512); }, 'Plain text to be converted to QR code image.')
    ->param('size', 400, function () { return new Range(0, 1000); }, 'QR code size. Pass an integer between 0 to 1000. Defaults to 400.', true)
    ->param('margin', 1, function () { return new Range(0, 10); }, 'Margin from edge. Pass an integer between 0 to 10. Defaults to 1.', true)
    ->param('download', 0, function () { return new Range(0, 1); }, 'Return resulting image with \'Content-Disposition: attachment \' headers for the browser to start downloading it. Pass 0 for no header, or 1 for otherwise. Default value is set to 0.', true)
    ->label('scope', 'avatars.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'avatars')
    ->label('sdk.method', 'getQR')
    ->label('sdk.description', '/docs/references/avatars/get-qr.md')
    ->action(
        function ($text, $size, $margin, $download) use ($response) {
            $renderer = new ImageRenderer(
                new RendererStyle($size, $margin),
                new ImagickImageBackEnd('png', 100)
            );

            $writer = new Writer($renderer);

            if ($download) {
                $response->addHeader('Content-Disposition', 'attachment; filename="qr.png"');
            }

            $response
                ->addHeader('Expires', date('D, d M Y H:i:s', time() + (60 * 60 * 24 * 45)).' GMT') // 45 days cache
                ->setContentType('image/png')
                ->send('', $writer->writeString($text))
            ;
        }
    );