<?php

use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\URL;
use Appwrite\URL\URL as URLParse;
use Appwrite\Utopia\Response;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Utopia\App;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Image\Image;
use Utopia\Validator\Boolean;
use Utopia\Validator\HexColor;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

$avatarCallback = function (string $type, string $code, int $width, int $height, int $quality, Response $response) {

    $code = \strtolower($code);
    $type = \strtolower($type);
    $set = Config::getParam('avatar-' . $type, []);

    if (empty($set)) {
        throw new Exception(Exception::AVATAR_SET_NOT_FOUND);
    }

    if (!\array_key_exists($code, $set)) {
        throw new Exception(Exception::AVATAR_NOT_FOUND);
    }

    if (!\extension_loaded('imagick')) {
        throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Imagick extension is missing');
    }

    $output = 'png';
    $path = $set[$code];
    $type = 'png';

    if (!\is_readable($path)) {
        throw new Exception(Exception::GENERAL_SERVER_ERROR, 'File not readable in ' . $path);
    }

    $image = new Image(\file_get_contents($path));
    $image->crop((int) $width, (int) $height);
    $output = (empty($output)) ? $type : $output;
    $data = $image->output($output, $quality);
    $response
        ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + 60 * 60 * 24 * 30) . ' GMT')
        ->setContentType('image/png')
        ->file($data);
    unset($image);
};

App::get('/v1/avatars/credit-cards/:code')
    ->desc('Get Credit Card Icon')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('cache', true)
    ->label('cache.resource', 'avatar/credit-card')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
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
    ->action(fn (string $code, int $width, int $height, int $quality, Response $response) =>  $avatarCallback('credit-cards', $code, $width, $height, $quality, $response));

App::get('/v1/avatars/browsers/:code')
    ->desc('Get Browser Icon')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('cache', true)
    ->label('cache.resource', 'avatar/browser')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
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
    ->action(fn (string $code, int $width, int $height, int $quality, Response $response) => $avatarCallback('browsers', $code, $width, $height, $quality, $response));

App::get('/v1/avatars/flags/:code')
    ->desc('Get Country Flag')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('cache', true)
    ->label('cache.resource', 'avatar/flag')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
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
    ->action(fn (string $code, int $width, int $height, int $quality, Response $response) => $avatarCallback('flags', $code, $width, $height, $quality, $response));

App::get('/v1/avatars/image')
    ->desc('Get Image from URL')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('cache', true)
    ->label('cache.resource', 'avatar/image')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'avatars')
    ->label('sdk.method', 'getImage')
    ->label('sdk.methodType', 'location')
    ->label('sdk.description', '/docs/references/avatars/get-image.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_IMAGE)
    ->param('url', '', new URL(['http', 'https']), 'Image URL which you want to crop.')
    ->param('width', 400, new Range(0, 2000), 'Resize preview image width, Pass an integer between 0 to 2000. Defaults to 400.', true)
    ->param('height', 400, new Range(0, 2000), 'Resize preview image height, Pass an integer between 0 to 2000. Defaults to 400.', true)
    ->inject('response')
    ->action(function (string $url, int $width, int $height, Response $response) {

        $quality = 80;
        $output = 'png';
        $type = 'png';

        if (!\extension_loaded('imagick')) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Imagick extension is missing');
        }

        $fetch = @\file_get_contents($url, false);

        if (!$fetch) {
            throw new Exception(Exception::AVATAR_IMAGE_NOT_FOUND);
        }

        try {
            $image = new Image($fetch);
        } catch (\Exception $exception) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Unable to parse image');
        }

        $image->crop((int) $width, (int) $height);
        $output = (empty($output)) ? $type : $output;
        $data = $image->output($output, $quality);

        $response
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + 60 * 60 * 24 * 30) . ' GMT')
            ->setContentType('image/png')
            ->file($data);
        unset($image);
    });

App::get('/v1/avatars/favicon')
    ->desc('Get Favicon')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('cache', true)
    ->label('cache.resource', 'avatar/favicon')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'avatars')
    ->label('sdk.method', 'getFavicon')
    ->label('sdk.methodType', 'location')
    ->label('sdk.description', '/docs/references/avatars/get-favicon.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_IMAGE)
    ->param('url', '', new URL(['http', 'https']), 'Website URL which you want to fetch the favicon from.')
    ->inject('response')
    ->action(function (string $url, Response $response) {

        $width = 56;
        $height = 56;
        $quality = 80;
        $output = 'png';
        $type = 'png';

        if (!\extension_loaded('imagick')) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Imagick extension is missing');
        }

        $curl = \curl_init();

        \curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_URL => $url,
            CURLOPT_USERAGENT => \sprintf(
                APP_USERAGENT,
                App::getEnv('_APP_VERSION', 'UNKNOWN'),
                App::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS', APP_EMAIL_SECURITY)
            ),
        ]);

        $html = \curl_exec($curl);

        \curl_close($curl);

        if (!$html) {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
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
                throw new Exception(Exception::AVATAR_ICON_NOT_FOUND, 'Favicon not found');
            }
            $response
                ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + 60 * 60 * 24 * 30) . ' GMT')
                ->setContentType('image/x-icon')
                ->file($data);
        }

        $fetch = @\file_get_contents($outputHref, false);

        if (!$fetch) {
            throw new Exception(Exception::AVATAR_ICON_NOT_FOUND);
        }

        $image = new Image($fetch);
        $image->crop((int) $width, (int) $height);
        $output = (empty($output)) ? $type : $output;
        $data = $image->output($output, $quality);

        $response
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + 60 * 60 * 24 * 30) . ' GMT')
            ->setContentType('image/png')
            ->file($data);
        unset($image);
    });

App::get('/v1/avatars/qr')
    ->desc('Get QR Code')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'avatars')
    ->label('sdk.method', 'getQR')
    ->label('sdk.methodType', 'location')
    ->label('sdk.description', '/docs/references/avatars/get-qr.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_IMAGE_PNG)
    ->param('text', '', new Text(512), 'Plain text to be converted to QR code image.')
    ->param('size', 400, new Range(1, 1000), 'QR code size. Pass an integer between 1 to 1000. Defaults to 400.', true)
    ->param('margin', 1, new Range(0, 10), 'Margin from edge. Pass an integer between 0 to 10. Defaults to 1.', true)
    ->param('download', false, new Boolean(true), 'Return resulting image with \'Content-Disposition: attachment \' headers for the browser to start downloading it. Pass 0 for no header, or 1 for otherwise. Default value is set to 0.', true)
    ->inject('response')
    ->action(function (string $text, int $size, int $margin, bool $download, Response $response) {

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
            ->send($image->output('png', 9));
    });

App::get('/v1/avatars/initials')
    ->desc('Get User Initials')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('cache.resource', 'avatar/initials')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'avatars')
    ->label('sdk.method', 'getInitials')
    ->label('sdk.methodType', 'location')
    ->label('sdk.description', '/docs/references/avatars/get-initials.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_IMAGE_PNG)
    ->param('name', '', new Text(128), 'Full Name. When empty, current user name or email will be used. Max length: 128 chars.', true)
    ->param('width', 500, new Range(0, 2000), 'Image width. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('height', 500, new Range(0, 2000), 'Image height. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('background', '', new HexColor(), 'Changes background color. By default a random color will be picked and stay will persistent to the given name.', true)
    ->inject('response')
    ->inject('user')
    ->action(function (string $name, int $width, int $height, string $background, Response $response, Document $user) {

        $themes = [
            ['background' => '#FFA1CE'], // Default (Pink)
            ['background' => '#FDC584'], // Orange
            ['background' => '#94DBD1'], // Green
            ['background' => '#A1C4FF'], // Blue
            ['background' => '#FFA1CE'], // Pink
            ['background' => '#CBB1FC'] // Purple
        ];

        $name = (!empty($name)) ? $name : $user->getAttribute('name', $user->getAttribute('email', ''));
        $words = \explode(' ', \strtoupper($name));
        // if there is no space, try to split by `_` underscore
        $words = (count($words) == 1) ? \explode('_', \strtoupper($name)) : $words;

        $initials = null;
        $code = 0;

        foreach ($words as $key => $w) {
            $initials .= $w[0] ?? '';
            $code += (isset($w[0])) ? \ord($w[0]) : 0;

            if ($key == 1) {
                break;
            }
        }

        $rand = \substr($code, -1);

        // Wrap rand value to avoid out of range
        $rand = ($rand > \count($themes) - 1) ? $rand % \count($themes) : $rand;

        $background = (!empty($background)) ? '#' . $background : $themes[$rand]['background'];

        $image = new \Imagick();
        $punch = new \Imagick();
        $draw = new \ImagickDraw();
        $fontSize = \min($width, $height) / 2;

        $punch->newImage($width, $height, 'transparent');

        $draw->setFont(__DIR__ . "/../../assets/fonts/poppins-v9-latin-500.ttf");
        $image->setFont(__DIR__ . "/../../assets/fonts/poppins-v9-latin-500.ttf");

        $draw->setFillColor(new ImagickPixel('black'));
        $draw->setFontSize($fontSize);

        $draw->setTextAlignment(\Imagick::ALIGN_CENTER);
        $draw->annotation($width / 1.97, ($height / 2) + ($fontSize / 3), $initials);

        $punch->drawImage($draw);
        $punch->negateImage(true, Imagick::CHANNEL_ALPHA);

        $image->newImage($width, $height, $background);
        $image->setImageFormat("png");
        $image->compositeImage($punch, Imagick::COMPOSITE_COPYOPACITY, 0, 0);

        //$image->setImageCompressionQuality(9 - round(($quality / 100) * 9));

        $response
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + (60 * 60 * 24 * 45)) . ' GMT') // 45 days cache
            ->setContentType('image/png')
            ->file($image->getImageBlob());
    });

App::get('/v1/cards/cloud-og')
    ->desc('Get Cloud Card')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    // ->label('cache', true)
    // ->label('cache.resource', 'cards/cloud')
    ->label('docs', false)
    ->label('origin', '*')
    ->param('width', 0, new Range(0, 4000), 'Resize  image card width, Pass an integer between 0 to 4000.', true)
    ->param('height', 0, new Range(0, 4000), 'Resize image card height, Pass an integer between 0 to 4000.', true)
    ->inject('user')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (int $width, int $height, Document $user, Document $project, Database $dbForProject, Response $response) {
        // if ($user->isEmpty()) {
        //     throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN);
        // }

        $baseImage = new \Imagick("public/images/cards-cloud-og3.png");

        // $name = $user->getAttribute('name', 'Anonymous');
        // $createdAt = new \DateTime($user->getCreatedAt());
        // $memberSince = \strtoupper('Member since ' . $createdAt->format('M') . ' ' . $createdAt->format('d') . ', ' . $createdAt->format('o'));

        // try {
        //     $sessions = $user->getAttribute('sessions', []);
        //     $session = $sessions[0] ?? new Document();

        //     $provider = $session->getAttribute('provider');
        //     $refreshToken = $session->getAttribute('providerRefreshToken');

        //     $appId = $project->getAttribute('authProviders', [])[$provider . 'Appid'] ?? '';
        //     $appSecret = $project->getAttribute('authProviders', [])[$provider . 'Secret'] ?? '{}';

        //     $className = 'Appwrite\\Auth\\OAuth2\\' . \ucfirst($provider);

        //     if (!\class_exists($className)) {
        //         throw new Exception(Exception::PROJECT_PROVIDER_UNSUPPORTED);
        //     }

        //     $oauth2 = new $className($appId, $appSecret, '', [], []);

        //     $oauth2->refreshTokens($refreshToken);

        //     $accessToken = $oauth2->getAccessToken('');
        //     $refreshToken = $oauth2->getRefreshToken('');

        //     $session
        //         ->setAttribute('providerAccessToken', $accessToken)
        //         ->setAttribute('providerRefreshToken', $refreshToken)
        //         ->setAttribute('providerAccessTokenExpiry', DateTime::addSeconds(new \DateTime(), (int)$oauth2->getAccessTokenExpiry('')));

        //     $dbForProject->updateDocument('sessions', $session->getId(), $session);

        //     $dbForProject->deleteCachedDocument('users', $user->getId());

        //     $githubUser = $oauth2->getUserSlug($accessToken);

        //     $gitHub = $githubUser;
        // } catch (Exception $err) {
        //     $gitHub = '';
        //     \var_dump($err->getMessage());
        //     \var_dump($err->getTraceAsString());
        //     \var_dump($err->getLine());
        //     \var_dump($err->getFile());
        // }

        // setlocale(LC_ALL, "en_US.utf8");
        // $name = \iconv("utf-8", "ascii//TRANSLIT", $name);
        // $memberSince = \iconv("utf-8", "ascii//TRANSLIT", $memberSince);
        // $gitHub = \iconv("utf-8", "ascii//TRANSLIT", $gitHub);

        $name = 'Matej Bačo';
        $memberSince = 'Member since 12 Nov 2023';
        $gitHub = 'meldiron';

        $text = new \ImagickDraw();
        $text->setTextAlignment(Imagick::ALIGN_CENTER);
        $text->setFont("public/fonts/Poppins-Bold.ttf");
        $text->setFillColor(new ImagickPixel('#FFFFFF'));
        $text->setFontSize(58);
        $text->setFontWeight(700);

        $text->skewY(20);
        $text->skewX(20);
        $text->setGravity(Imagick::GRAVITY_CENTER);
        $text->annotation(350, 635, $name);


        $baseImage->drawImage($text);
        // $baseImage->annotateImage($text, 550, 535, -8.86, $name);

        // $text = new \ImagickDraw();
        // $text->setTextAlignment(Imagick::ALIGN_CENTER);
        // $text->setFont("public/fonts/Inter-Medium.ttf");
        // $text->setFillColor(new ImagickPixel('#FFB9CC'));
        // $text->setFontSize(24);
        // $text->setFontWeight(500);
        // $text->setTextKerning(1.12);
        // $baseImage->annotateImage($text, 570, 630, -22.24, $memberSince);

        // $text = new \ImagickDraw();
        // $text->setTextAlignment(Imagick::ALIGN_CENTER);
        // $text->setFont("public/fonts/Inter-Regular.ttf");
        // $text->setFillColor(new ImagickPixel('#FFB9CC'));
        // $text->setFontSize(26);
        // $text->setFontWeight(400);
        // $baseImage->annotateImage($text, 805, 380, 64.75, $gitHub);

        // $metrics = $baseImage->queryFontMetrics($text, $gitHub);
        // \var_dump($metrics['textWidth']);

        if (!empty($width) || !empty($height)) {
            $baseImage->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
        }

        $response
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + (60 * 60 * 24 * 45)) . ' GMT') // 45 days cache
            ->setContentType('image/png')
            ->file($baseImage->getImageBlob());
    });
