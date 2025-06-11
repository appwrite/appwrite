<?php

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\URL\URL as URLParse;
use Appwrite\Utopia\Response;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Utopia\App;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Domains\Domain;
use Utopia\Fetch\Client;
use Utopia\Image\Image;
use Utopia\Logger\Logger;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\HexColor;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\URL;
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
    $path = $set[$code]['path'];
    $type = 'png';

    if (!\is_readable($path)) {
        throw new Exception(Exception::GENERAL_SERVER_ERROR, 'File not readable in ' . $path);
    }

    $image = new Image(\file_get_contents($path));
    $image->crop((int) $width, (int) $height);
    $output = (empty($output)) ? $type : $output;
    $data = $image->output($output, $quality);
    $response
        ->addHeader('Cache-Control', 'private, max-age=2592000') // 30 days
        ->setContentType('image/png')
        ->file($data);
    unset($image);
};

$getUserGitHub = function (string $userId, Document $project, Database $dbForProject, Database $dbForPlatform, ?Logger $logger) {
    try {
        $user = Authorization::skip(fn () => $dbForPlatform->getDocument('users', $userId));

        $sessions = $user->getAttribute('sessions', []);

        $gitHubSession = null;
        foreach ($sessions as $session) {
            if ($session->getAttribute('provider', '') === 'github') {
                $gitHubSession = $session;
                break;
            }
        }

        if (empty($gitHubSession)) {
            throw new Exception(Exception::USER_SESSION_NOT_FOUND, 'GitHub session not found.');
        }

        $provider = $gitHubSession->getAttribute('provider', '');
        $accessToken = $gitHubSession->getAttribute('providerAccessToken');
        $accessTokenExpiry = $gitHubSession->getAttribute('providerAccessTokenExpiry');
        $refreshToken = $gitHubSession->getAttribute('providerRefreshToken');

        $appId = $project->getAttribute('oAuthProviders', [])[$provider . 'Appid'] ?? '';
        $appSecret = $project->getAttribute('oAuthProviders', [])[$provider . 'Secret'] ?? '{}';

        $className = 'Appwrite\\Auth\\OAuth2\\' . \ucfirst($provider);

        if (!\class_exists($className)) {
            throw new Exception(Exception::PROJECT_PROVIDER_UNSUPPORTED);
        }

        $oauth2 = new $className($appId, $appSecret, '', [], []);

        $isExpired = new \DateTime($accessTokenExpiry) < new \DateTime('now');
        if ($isExpired) {
            try {
                $oauth2->refreshTokens($refreshToken);

                $accessToken = $oauth2->getAccessToken('');
                $refreshToken = $oauth2->getRefreshToken('');

                $verificationId = $oauth2->getUserID($accessToken);

                if (empty($verificationId)) {
                    throw new \Exception("Locked tokens."); // Race codition, handeled in catch
                }

                $gitHubSession
                    ->setAttribute('providerAccessToken', $accessToken)
                    ->setAttribute('providerRefreshToken', $refreshToken)
                    ->setAttribute('providerAccessTokenExpiry', DateTime::addSeconds(new \DateTime(), (int)$oauth2->getAccessTokenExpiry('')));

                Authorization::skip(fn () => $dbForProject->updateDocument('sessions', $gitHubSession->getId(), $gitHubSession));

                $dbForProject->purgeCachedDocument('users', $user->getId());
            } catch (Throwable $err) {
                $index = 0;
                do {
                    $previousAccessToken = $gitHubSession->getAttribute('providerAccessToken');

                    $user = Authorization::skip(fn () => $dbForPlatform->getDocument('users', $userId));
                    $sessions = $user->getAttribute('sessions', []);

                    $gitHubSession = new Document();
                    foreach ($sessions as $session) {
                        if ($session->getAttribute('provider', '') === 'github') {
                            $gitHubSession = $session;
                            break;
                        }
                    }

                    $accessToken = $gitHubSession->getAttribute('providerAccessToken');

                    if ($accessToken !== $previousAccessToken) {
                        break;
                    }

                    $index++;
                    \usleep(500000);
                } while ($index < 10);
            }
        }

        $oauth2 = new $className($appId, $appSecret, '', [], []);
        $githubUser = $oauth2->getUserSlug($accessToken);
        $githubId = $oauth2->getUserID($accessToken);

        return [
            'name' => $githubUser,
            'id' => $githubId
        ];
    } catch (Exception $error) {
        return [];
    }
};

App::get('/v1/avatars/credit-cards/:code')
    ->desc('Get credit card icon')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('cache', true)
    ->label('cache.resource', 'avatar/credit-card')
    ->label('sdk', new Method(
        namespace: 'avatars',
        group: null,
        name: 'getCreditCard',
        description: '/docs/references/avatars/get-credit-card.md',
        auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        type: MethodType::LOCATION,
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::IMAGE_PNG
    ))
    ->param('code', '', new WhiteList(\array_keys(Config::getParam('avatar-credit-cards'))), 'Credit Card Code. Possible values: ' . \implode(', ', \array_keys(Config::getParam('avatar-credit-cards'))) . '.')
    ->param('width', 100, new Range(0, 2000), 'Image width. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('height', 100, new Range(0, 2000), 'Image height. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('quality', -1, new Range(-1, 100), 'Image quality. Pass an integer between 0 to 100. Defaults to keep existing image quality.', true)
    ->inject('response')
    ->action(fn (string $code, int $width, int $height, int $quality, Response $response) =>  $avatarCallback('credit-cards', $code, $width, $height, $quality, $response));

App::get('/v1/avatars/browsers/:code')
    ->desc('Get browser icon')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('cache', true)
    ->label('cache.resource', 'avatar/browser')
    ->label('sdk', new Method(
        namespace: 'avatars',
        group: null,
        name: 'getBrowser',
        description: '/docs/references/avatars/get-browser.md',
        auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        type: MethodType::LOCATION,
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::IMAGE_PNG
    ))
    ->param('code', '', new WhiteList(\array_keys(Config::getParam('avatar-browsers'))), 'Browser Code.')
    ->param('width', 100, new Range(0, 2000), 'Image width. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('height', 100, new Range(0, 2000), 'Image height. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('quality', -1, new Range(-1, 100), 'Image quality. Pass an integer between 0 to 100. Defaults to keep existing image quality.', true)
    ->inject('response')
    ->action(fn (string $code, int $width, int $height, int $quality, Response $response) => $avatarCallback('browsers', $code, $width, $height, $quality, $response));

App::get('/v1/avatars/flags/:code')
    ->desc('Get country flag')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('cache', true)
    ->label('cache.resource', 'avatar/flag')
    ->label('sdk', new Method(
        namespace: 'avatars',
        group: null,
        name: 'getFlag',
        description: '/docs/references/avatars/get-flag.md',
        auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        type: MethodType::LOCATION,
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::IMAGE_PNG
    ))
    ->param('code', '', new WhiteList(\array_keys(Config::getParam('avatar-flags'))), 'Country Code. ISO Alpha-2 country code format.')
    ->param('width', 100, new Range(0, 2000), 'Image width. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('height', 100, new Range(0, 2000), 'Image height. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('quality', -1, new Range(-1, 100), 'Image quality. Pass an integer between 0 to 100. Defaults to keep existing image quality.', true)
    ->inject('response')
    ->action(fn (string $code, int $width, int $height, int $quality, Response $response) => $avatarCallback('flags', $code, $width, $height, $quality, $response));

App::get('/v1/avatars/image')
    ->desc('Get image from URL')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('cache', true)
    ->label('cache.resource', 'avatar/image')
    ->label('sdk', new Method(
        namespace: 'avatars',
        group: null,
        name: 'getImage',
        description: '/docs/references/avatars/get-image.md',
        auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        type: MethodType::LOCATION,
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::IMAGE
    ))
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

        $domain = new Domain(\parse_url($url, PHP_URL_HOST));

        if (!$domain->isKnown()) {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
        }

        $client = new Client();
        try {
            $res = $client
                ->setAllowRedirects(false)
                ->fetch($url);
        } catch (\Throwable) {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
        }

        if ($res->getStatusCode() !== 200) {
            throw new Exception(Exception::AVATAR_IMAGE_NOT_FOUND);
        }

        try {
            $image = new Image($res->getBody());
        } catch (\Throwable $exception) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Unable to parse image');
        }

        $image->crop((int) $width, (int) $height);
        $output = (empty($output)) ? $type : $output;
        $data = $image->output($output, $quality);

        $response
            ->addHeader('Cache-Control', 'private, max-age=2592000') // 30 days
            ->setContentType('image/png')
            ->file($data);
        unset($image);
    });

App::get('/v1/avatars/favicon')
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
        auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
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
    ->action(function (string $url, Response $response) {

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
                ->setAllowRedirects(false)
                ->setUserAgent(\sprintf(
                    APP_USERAGENT,
                    System::getEnv('_APP_VERSION', 'UNKNOWN'),
                    System::getEnv('_APP_EMAIL_SECURITY', System::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS', APP_EMAIL_SECURITY))
                ))
                ->fetch($url);
        } catch (\Throwable) {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
        }

        if ($res->getStatusCode() !== 200) {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
        }

        $doc = new DOMDocument();
        $doc->strictErrorChecking = false;
        @$doc->loadHTML($res->getBody());

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

                            $sizeWidth = (int) ($size[0] ?? 0);
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

        $domain = new Domain(\parse_url($outputHref, PHP_URL_HOST));

        if (!$domain->isKnown()) {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
        }

        $client = new Client();
        try {
            $res = $client
                ->setAllowRedirects(false)
                ->fetch($outputHref);
        } catch (\Throwable) {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
        }

        if ($res->getStatusCode() !== 200) {
            throw new Exception(Exception::AVATAR_ICON_NOT_FOUND);
        }

        $data = $res->getBody();

        if ('ico' == $outputExt) { // Skip crop, Imagick isn\'t supporting icon files
            if (empty($data) || (\mb_substr($data, 0, 5) === '<html') || \mb_substr($data, 0, 5) === '<!doc') {
                throw new Exception(Exception::AVATAR_ICON_NOT_FOUND, 'Favicon not found');
            }
            $response
                ->addHeader('Cache-Control', 'private, max-age=2592000') // 30 days
                ->setContentType('image/x-icon')
                ->file($data);
        }

        $image = new Image($data);
        $image->crop((int) $width, (int) $height);
        $output = (empty($output)) ? $type : $output;
        $data = $image->output($output, $quality);

        $response
            ->addHeader('Cache-Control', 'private, max-age=2592000') // 30 days
            ->setContentType('image/png')
            ->file($data);
        unset($image);
    });

App::get('/v1/avatars/qr')
    ->desc('Get QR code')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('sdk', new Method(
        namespace: 'avatars',
        group: null,
        name: 'getQR',
        description: '/docs/references/avatars/get-qr.md',
        auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        type: MethodType::LOCATION,
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::IMAGE_PNG
    ))
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
            'scale' => 15,
        ]);

        $qrcode = new QRCode($options);

        if ($download) {
            $response->addHeader('Content-Disposition', 'attachment; filename="qr.png"');
        }

        $image = new Image($qrcode->render($text));
        $image->crop((int) $size, (int) $size);

        $response
            ->addHeader('Cache-Control', 'private, max-age=3888000') // 45 days
            ->setContentType('image/png')
            ->send($image->output('png', 90));
    });

App::get('/v1/avatars/initials')
    ->desc('Get user initials')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('cache.resource', 'avatar/initials')
    ->label('sdk', new Method(
        namespace: 'avatars',
        group: null,
        name: 'getInitials',
        description: '/docs/references/avatars/get-initials.md',
        auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        type: MethodType::LOCATION,
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::IMAGE_PNG
    ))
    ->param('name', '', new Text(128), 'Full Name. When empty, current user name or email will be used. Max length: 128 chars.', true)
    ->param('width', 500, new Range(0, 2000), 'Image width. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('height', 500, new Range(0, 2000), 'Image height. Pass an integer between 0 to 2000. Defaults to 100.', true)
    ->param('background', '', new HexColor(), 'Changes background color. By default a random color will be picked and stay will persistent to the given name.', true)
    ->inject('response')
    ->inject('user')
    ->action(function (string $name, int $width, int $height, string $background, Response $response, Document $user) {

        $themes = [
            ['background' => '#FD366E'], // Default (Pink)
            ['background' => '#FE9567'], // Orange
            ['background' => '#7C67FE'], // Purple
            ['background' => '#68A3FE'], // Blue
            ['background' => '#85DBD8'], // Mint
        ];

        $name = (!empty($name)) ? $name : $user->getAttribute('name', $user->getAttribute('email', ''));
        $words = \explode(' ', \strtoupper($name));
        // if there is no space, try to split by `_` underscore
        $words = (count($words) == 1) ? \explode('_', \strtoupper($name)) : $words;

        $initials = '';
        $code = 0;

        foreach ($words as $key => $w) {
            if (ctype_alnum($w[0] ?? '')) {
                $initials .= $w[0];
                $code += ord($w[0]);

                if ($key == 1) {
                    break;
                }
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

        $draw->setFont(__DIR__ . "/../../assets/fonts/inter-v8-latin-regular.woff2");
        $image->setFont(__DIR__ . "/../../assets/fonts/inter-v8-latin-regular.woff2");

        $draw->setFillColor(new ImagickPixel('black'));
        $draw->setFontSize($fontSize);

        $draw->setTextAlignment(\Imagick::ALIGN_CENTER);
        $draw->annotation($width / 1.97, ($height / 2) + ($fontSize / 3), $initials);

        $punch->drawImage($draw);
        $punch->negateImage(true, Imagick::CHANNEL_ALPHA);

        $image->newImage($width, $height, $background);
        $image->setImageFormat("png");
        $image->compositeImage($punch, Imagick::COMPOSITE_COPYOPACITY, 0, 0);

        $response
            ->addHeader('Cache-Control', 'private, max-age=3888000') // 45 days
            ->setContentType('image/png')
            ->file($image->getImageBlob());
    });

App::get('/v1/cards/cloud')
    ->desc('Get front Of Cloud Card')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('cache', true)
    ->label('cache.resourceType', 'cards/cloud')
    ->label('cache.resource', 'card/{request.userId}')
    ->label('docs', false)
    ->label('origin', '*')
    ->param('userId', '', new UID(), 'User ID.', true)
    ->param('mock', '', new WhiteList(['employee', 'employee-2digit', 'hero', 'contributor', 'normal', 'platinum', 'normal-no-github', 'normal-long']), 'Mocking behaviour.', true)
    ->param('width', 0, new Range(0, 512), 'Resize  image width, Pass an integer between 0 to 512.', true)
    ->param('height', 0, new Range(0, 320), 'Resize image height, Pass an integer between 0 to 320.', true)
    ->inject('user')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('dbForPlatform')
    ->inject('response')
    ->inject('heroes')
    ->inject('contributors')
    ->inject('employees')
    ->inject('logger')
    ->action(function (string $userId, string $mock, int $width, int $height, Document $user, Document $project, Database $dbForProject, Database $dbForPlatform, Response $response, array $heroes, array $contributors, array $employees, ?Logger $logger) use ($getUserGitHub) {
        $user = Authorization::skip(fn () => $dbForPlatform->getDocument('users', $userId));

        if ($user->isEmpty() && empty($mock)) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        if (!$mock) {
            $name = $user->getAttribute('name', 'Anonymous');
            $email = $user->getAttribute('email', '');
            $createdAt = new \DateTime($user->getCreatedAt());

            $gitHub = $getUserGitHub($user->getId(), $project, $dbForProject, $dbForPlatform, $logger);
            $githubName = $gitHub['name'] ?? '';
            $githubId = $gitHub['id'] ?? '';

            $isHero = \array_key_exists($email, $heroes);
            $isContributor = \in_array($githubId, $contributors);
            $isEmployee = \array_key_exists($email, $employees);
            $employeeNumber = $isEmployee ? $employees[$email]['spot'] : '';

            if ($isHero) {
                $createdAt = new \DateTime($heroes[$email]['memberSince'] ?? '');
            } elseif ($isEmployee) {
                $createdAt = new \DateTime($employees[$email]['memberSince'] ?? '');
            }

            if (!$isEmployee && !empty($githubName)) {
                $employeeGitHub = \array_search(\strtolower($githubName), \array_map(fn ($employee) => \strtolower($employee['gitHub']) ?? '', $employees));
                if (!empty($employeeGitHub)) {
                    $isEmployee = true;
                    $employeeNumber = $isEmployee ? $employees[$employeeGitHub]['spot'] : '';
                    $createdAt = new \DateTime($employees[$employeeGitHub]['memberSince'] ?? '');
                }
            }

            $isPlatinum = $user->getInternalId() % 100 === 0;
        } else {
            $name = $mock === 'normal-long' ? 'Sir First Walter O\'Brian Junior' : 'Walter O\'Brian';
            $createdAt = new \DateTime('now');
            $githubName = $mock === 'normal-no-github' ? '' : ($mock === 'normal-long' ? 'sir-first-walterobrian-junior' : 'walterobrian');
            $isHero = $mock === 'hero';
            $isContributor = $mock === 'contributor';
            $isEmployee = \str_starts_with($mock, 'employee');
            $employeeNumber = match ($mock) {
                'employee' => '1',
                'employee-2digit' => '18',
                default => ''
            };

            $isPlatinum = $mock === 'platinum';
        }

        if ($isEmployee) {
            $isContributor = false;
            $isHero = false;
        }

        if ($isHero) {
            $isContributor = false;
            $isEmployee = false;
        }

        if ($isContributor) {
            $isHero = false;
            $isEmployee = false;
        }

        $isGolden = $isEmployee || $isHero || $isContributor;
        $isPlatinum = $isGolden ? false : $isPlatinum;
        $memberSince = \strtoupper('Member since ' . $createdAt->format('M') . ' ' . $createdAt->format('d') . ', ' . $createdAt->format('o'));

        $imagePath = $isGolden ? 'front-golden.png' : ($isPlatinum ? 'front-platinum.png' : 'front.png');

        $baseImage = new \Imagick(__DIR__ . '/../../../public/images/cards/cloud/' . $imagePath);

        if ($isEmployee) {
            $image = new Imagick(__DIR__ . '/../../../public/images/cards/cloud/employee.png');
            $image->setGravity(Imagick::GRAVITY_CENTER);
            $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 793, 35);

            $text = new \ImagickDraw();
            $text->setTextAlignment(Imagick::ALIGN_CENTER);
            $text->setFont(__DIR__ . '/../../../public/fonts/Inter-Bold.ttf');
            $text->setFillColor(new \ImagickPixel('#FFFADF'));
            $text->setFontSize(\strlen($employeeNumber) <= 2 ? 54 : 48);
            $text->setFontWeight(700);
            $metricsText = $baseImage->queryFontMetrics($text, $employeeNumber);

            $hashtag = new \ImagickDraw();
            $hashtag->setTextAlignment(Imagick::ALIGN_CENTER);
            $hashtag->setFont(__DIR__ . '/../../../public/fonts/Inter-Bold.ttf');
            $hashtag->setFillColor(new \ImagickPixel('#FFFADF'));
            $hashtag->setFontSize(28);
            $hashtag->setFontWeight(700);
            $metricsHashtag = $baseImage->queryFontMetrics($hashtag, '#');

            $startX = 898;
            $totalWidth = $metricsHashtag['textWidth'] + 12 + $metricsText['textWidth'];

            $hashtagX = ($metricsHashtag['textWidth'] / 2);
            $textX = $hashtagX + 12 + ($metricsText['textWidth'] / 2);

            $hashtagX -= $totalWidth / 2;
            $textX -= $totalWidth / 2;

            $hashtagX += $startX;
            $textX += $startX;

            $baseImage->annotateImage($hashtag, $hashtagX, 150, 0, '#');
            $baseImage->annotateImage($text, $textX, 150, 0, $employeeNumber);
        }

        if ($isContributor) {
            $image = new Imagick(__DIR__ . '/../../../public/images/cards/cloud/contributor.png');
            $image->setGravity(Imagick::GRAVITY_CENTER);
            $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 793, 34);
        }

        if ($isHero) {
            $image = new Imagick(__DIR__ . '/../../../public/images/cards/cloud/hero.png');
            $image->setGravity(Imagick::GRAVITY_CENTER);
            $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 793, 34);
        }

        setlocale(LC_ALL, "en_US.utf8");
        // $name = \iconv("utf-8", "ascii//TRANSLIT", $name);
        // $memberSince = \iconv("utf-8", "ascii//TRANSLIT", $memberSince);
        // $githubName = \iconv("utf-8", "ascii//TRANSLIT", $githubName);

        $text = new \ImagickDraw();
        $text->setTextAlignment(Imagick::ALIGN_CENTER);
        $text->setFont(__DIR__ . '/../../../public/fonts/Inter-Bold.ttf');
        $text->setFillColor(new \ImagickPixel('#FFFFFF'));

        if (\strlen($name) > 32) {
            $name = \substr($name, 0, 32);
        }

        if (\strlen($name) <= 23) {
            $text->setFontSize(80);
            $scalingDown = false;
        } else {
            $text->setFontSize(54);
            $scalingDown = true;
        }
        $text->setFontWeight(700);
        $baseImage->annotateImage($text, 512, 477, 0, $name);

        $text = new \ImagickDraw();
        $text->setTextAlignment(Imagick::ALIGN_CENTER);
        $text->setFont(__DIR__ . '/../../../public/fonts/Inter-SemiBold.ttf');
        $text->setFillColor(new \ImagickPixel($isGolden || $isPlatinum ? '#FFFFFF' : '#FFB9CC'));
        $text->setFontSize(27);
        $text->setFontWeight(600);
        $text->setTextKerning(1.08);
        $baseImage->annotateImage($text, 512, 541, 0, \strtoupper($memberSince));

        if (!empty($githubName)) {
            $text = new \ImagickDraw();
            $text->setTextAlignment(Imagick::ALIGN_CENTER);
            $text->setFont(__DIR__ . '/../../../public/fonts/Inter-Regular.ttf');
            $text->setFillColor(new \ImagickPixel('#FFFFFF'));
            $text->setFontSize($scalingDown ? 28 : 32);
            $text->setFontWeight(400);
            $metrics = $baseImage->queryFontMetrics($text, $githubName);

            $baseImage->annotateImage($text, 512 + 20 + 4, 373 + ($scalingDown ? 2 : 0), 0, $githubName);

            $image = new Imagick(__DIR__ . '/../../../public/images/cards/cloud/github.png');
            $image->setGravity(Imagick::GRAVITY_CENTER);
            $precisionFix = 5;
            $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 512 - ($metrics['textWidth'] / 2) - 20 - 4, 373 - ($metrics['textHeight'] - $precisionFix));
        }

        if (!empty($width) || !empty($height)) {
            $baseImage->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
        }

        $response
            ->addHeader('Cache-Control', 'private, max-age=3888000') // 45 days
            ->setContentType('image/png')
            ->file($baseImage->getImageBlob());
    });

App::get('/v1/cards/cloud-back')
    ->desc('Get back Of Cloud Card')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('cache', true)
    ->label('cache.resourceType', 'cards/cloud-back')
    ->label('cache.resource', 'card-back/{request.userId}')
    ->label('docs', false)
    ->label('origin', '*')
    ->param('userId', '', new UID(), 'User ID.', true)
    ->param('mock', '', new WhiteList(['golden', 'normal', 'platinum']), 'Mocking behaviour.', true)
    ->param('width', 0, new Range(0, 512), 'Resize  image width, Pass an integer between 0 to 512.', true)
    ->param('height', 0, new Range(0, 320), 'Resize image height, Pass an integer between 0 to 320.', true)
    ->inject('user')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('dbForPlatform')
    ->inject('response')
    ->inject('heroes')
    ->inject('contributors')
    ->inject('employees')
    ->inject('logger')
    ->action(function (string $userId, string $mock, int $width, int $height, Document $user, Document $project, Database $dbForProject, Database $dbForPlatform, Response $response, array $heroes, array $contributors, array $employees, ?Logger $logger) use ($getUserGitHub) {
        $user = Authorization::skip(fn () => $dbForPlatform->getDocument('users', $userId));

        if ($user->isEmpty() && empty($mock)) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        if (!$mock) {
            $userId = $user->getId();
            $email = $user->getAttribute('email', '');

            $gitHub = $getUserGitHub($user->getId(), $project, $dbForProject, $dbForPlatform, $logger);
            $githubId = $gitHub['id'] ?? '';

            $isHero = \array_key_exists($email, $heroes);
            $isContributor = \in_array($githubId, $contributors);
            $isEmployee = \array_key_exists($email, $employees);

            $isGolden = $isEmployee || $isHero || $isContributor;
            $isPlatinum = $user->getInternalId() % 100 === 0;
        } else {
            $userId = '63e0bcf3c3eb803ba530';

            $isGolden = $mock === 'golden';
            $isPlatinum = $mock === 'platinum';
        }

        $userId = 'UID ' . $userId;

        $isPlatinum = $isGolden ? false : $isPlatinum;

        $imagePath = $isGolden ? 'back-golden.png' : ($isPlatinum ? 'back-platinum.png' : 'back.png');

        $baseImage = new \Imagick(__DIR__ . '/../../../public/images/cards/cloud/' . $imagePath);

        setlocale(LC_ALL, "en_US.utf8");
        // $userId = \iconv("utf-8", "ascii//TRANSLIT", $userId);

        $text = new \ImagickDraw();
        $text->setTextAlignment(Imagick::ALIGN_CENTER);
        $text->setFont(__DIR__ . '/../../../public/fonts/SourceCodePro-Regular.ttf');
        $text->setFillColor(new \ImagickPixel($isGolden ? '#664A1E' : ($isPlatinum ? '#555555' : '#E8E9F0')));
        $text->setFontSize(28);
        $text->setFontWeight(400);
        $baseImage->annotateImage($text, 512, 596, 0, $userId);

        if (!empty($width) || !empty($height)) {
            $baseImage->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
        }

        $response
            ->addHeader('Cache-Control', 'private, max-age=3888000') // 45 days
            ->setContentType('image/png')
            ->file($baseImage->getImageBlob());
    });

App::get('/v1/cards/cloud-og')
    ->desc('Get OG image From Cloud Card')
    ->groups(['api', 'avatars'])
    ->label('scope', 'avatars.read')
    ->label('cache', true)
    ->label('cache.resourceType', 'cards/cloud-og')
    ->label('cache.resource', 'card-og/{request.userId}')
    ->label('docs', false)
    ->label('origin', '*')
    ->param('userId', '', new UID(), 'User ID.', true)
    ->param('mock', '', new WhiteList(['employee', 'employee-2digit', 'hero', 'contributor', 'normal', 'platinum', 'normal-no-github', 'normal-long', 'normal-long-right', 'normal-long-middle', 'normal-bg2', 'normal-bg3', 'normal-right', 'normal-middle', 'platinum-right', 'platinum-middle', 'hero-middle', 'hero-right', 'contributor-right', 'employee-right', 'contributor-middle', 'employee-middle', 'employee-2digit-middle', 'employee-2digit-right']), 'Mocking behaviour.', true)
    ->param('width', 0, new Range(0, 1024), 'Resize  image card width, Pass an integer between 0 to 1024.', true)
    ->param('height', 0, new Range(0, 1024), 'Resize image card height, Pass an integer between 0 to 1024.', true)
    ->inject('user')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('dbForPlatform')
    ->inject('response')
    ->inject('heroes')
    ->inject('contributors')
    ->inject('employees')
    ->inject('logger')
    ->action(function (string $userId, string $mock, int $width, int $height, Document $user, Document $project, Database $dbForProject, Database $dbForPlatform, Response $response, array $heroes, array $contributors, array $employees, ?Logger $logger) use ($getUserGitHub) {
        $user = Authorization::skip(fn () => $dbForPlatform->getDocument('users', $userId));

        if ($user->isEmpty() && empty($mock)) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        if (!$mock) {
            $internalId = $user->getInternalId();
            $bgVariation = $internalId % 3 === 0 ? '1' : ($internalId % 3 === 1 ? '2' : '3');
            $cardVariation = $internalId % 3 === 0 ? '1' : ($internalId % 3 === 1 ? '2' : '3');

            $name = $user->getAttribute('name', 'Anonymous');
            $email = $user->getAttribute('email', '');
            $createdAt = new \DateTime($user->getCreatedAt());

            $gitHub = $getUserGitHub($user->getId(), $project, $dbForProject, $dbForPlatform, $logger);
            $githubName = $gitHub['name'] ?? '';
            $githubId = $gitHub['id'] ?? '';

            $isHero = \array_key_exists($email, $heroes);
            $isContributor = \in_array($githubId, $contributors);
            $isEmployee = \array_key_exists($email, $employees);
            $employeeNumber = $isEmployee ? $employees[$email]['spot'] : '';

            if ($isHero) {
                $createdAt = new \DateTime($heroes[$email]['memberSince'] ?? '');
            } elseif ($isEmployee) {
                $createdAt = new \DateTime($employees[$email]['memberSince'] ?? '');
            }

            if (!$isEmployee && !empty($githubName)) {
                $employeeGitHub = \array_search(\strtolower($githubName), \array_map(fn ($employee) => \strtolower($employee['gitHub']) ?? '', $employees));
                if (!empty($employeeGitHub)) {
                    $isEmployee = true;
                    $employeeNumber = $isEmployee ? $employees[$employeeGitHub]['spot'] : '';
                    $createdAt = new \DateTime($employees[$employeeGitHub]['memberSince'] ?? '');
                }
            }

            $isPlatinum = $user->getInternalId() % 100 === 0;
        } else {
            $bgVariation = \str_ends_with($mock, '-bg2') ? '2' : (\str_ends_with($mock, '-bg3') ? '3' : '1');
            $cardVariation = \str_ends_with($mock, '-right') ? '2' : (\str_ends_with($mock, '-middle') ? '3' : '1');
            $name = \str_starts_with($mock, 'normal-long') ? 'Sir First Walter O\'Brian Junior' : 'Walter O\'Brian';
            $createdAt = new \DateTime('now');
            $githubName = $mock === 'normal-no-github' ? '' : (\str_starts_with($mock, 'normal-long') ? 'sir-first-walterobrian-junior' : 'walterobrian');
            $isHero = \str_starts_with($mock, 'hero');
            $isContributor = \str_starts_with($mock, 'contributor');
            $isEmployee = \str_starts_with($mock, 'employee');
            $employeeNumber = match ($mock) {
                'employee' => '1',
                'employee-right' => '1',
                'employee-middle' => '1',
                'employee-2digit' => '18',
                'employee-2digit-right' => '18',
                'employee-2digit-middle' => '18',
                default => ''
            };

            $isPlatinum = \str_starts_with($mock, 'platinum');
        }

        if ($isEmployee) {
            $isContributor = false;
            $isHero = false;
        }

        if ($isHero) {
            $isContributor = false;
            $isEmployee = false;
        }

        if ($isContributor) {
            $isHero = false;
            $isEmployee = false;
        }

        $isGolden = $isEmployee || $isHero || $isContributor;
        $isPlatinum = $isGolden ? false : $isPlatinum;
        $memberSince = \strtoupper('Member since ' . $createdAt->format('M') . ' ' . $createdAt->format('d') . ', ' . $createdAt->format('o'));

        $baseImage = new \Imagick(__DIR__ . "/../../../public/images/cards/cloud/og-background{$bgVariation}.png");

        $cardType = $isGolden ? '-golden' : ($isPlatinum ? '-platinum' : '');

        $image = new Imagick(__DIR__ . "/../../../public/images/cards/cloud/og-card{$cardType}{$cardVariation}.png");
        $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 1008 / 2 - $image->getImageWidth() / 2, 1008 / 2 - $image->getImageHeight() / 2);

        $imageLogo = new Imagick(__DIR__ . '/../../../public/images/cards/cloud/og-background-logo.png');
        $imageShadow = new Imagick(__DIR__ . "/../../../public/images/cards/cloud/og-shadow{$cardType}.png");
        if ($cardVariation === '1') {
            $baseImage->compositeImage($imageLogo, Imagick::COMPOSITE_OVER, 32, 1008 - $imageLogo->getImageHeight() - 32);
            $baseImage->compositeImage($imageShadow, Imagick::COMPOSITE_OVER, -450, 700);
        } elseif ($cardVariation === '2') {
            $baseImage->compositeImage($imageLogo, Imagick::COMPOSITE_OVER, 1008 - $imageLogo->getImageWidth() - 32, 1008 - $imageLogo->getImageHeight() - 32);
            $baseImage->compositeImage($imageShadow, Imagick::COMPOSITE_OVER, -20, 710);
        } else {
            $baseImage->compositeImage($imageLogo, Imagick::COMPOSITE_OVER, 1008 - $imageLogo->getImageWidth() - 32, 1008 - $imageLogo->getImageHeight() - 32);
            $baseImage->compositeImage($imageShadow, Imagick::COMPOSITE_OVER, -135, 710);
        }

        if ($isEmployee) {
            $file = $cardVariation === '3' ? 'employee-skew.png' : 'employee.png';
            $image = new Imagick(__DIR__ . '/../../../public/images/cards/cloud/' . $file);
            $image->setGravity(Imagick::GRAVITY_CENTER);

            $hashtag = new \ImagickDraw();
            $hashtag->setTextAlignment(Imagick::ALIGN_LEFT);
            $hashtag->setFont(__DIR__ . '/../../../public/fonts/Inter-Bold.ttf');
            $hashtag->setFillColor(new \ImagickPixel('#FFFADF'));
            $hashtag->setFontSize(20);
            $hashtag->setFontWeight(700);

            $text = new \ImagickDraw();
            $text->setTextAlignment(Imagick::ALIGN_LEFT);
            $text->setFont(__DIR__ . '/../../../public/fonts/Inter-Bold.ttf');
            $text->setFillColor(new \ImagickPixel('#FFFADF'));
            $text->setFontSize(\strlen($employeeNumber) <= 1 ? 36 : 28);
            $text->setFontWeight(700);

            if ($cardVariation === '3') {
                $hashtag->setFontSize(16);
                $text->setFontSize(\strlen($employeeNumber) <= 1 ? 30 : 26);

                $hashtag->skewY(20);
                $hashtag->skewX(20);
                $text->skewY(20);
                $text->skewX(20);
            }

            $metricsHashtag = $baseImage->queryFontMetrics($hashtag, '#');
            $metricsText = $baseImage->queryFontMetrics($text, $employeeNumber);

            $group = new Imagick();
            $groupWidth = $metricsHashtag['textWidth'] + 6 + $metricsText['textWidth'];

            if ($cardVariation === '1') {
                $group->newImage($groupWidth, $metricsText['textHeight'], '#00000000');
                $group->annotateImage($hashtag, 0, $metricsText['textHeight'], 0, '#');
                $group->annotateImage($text, $metricsHashtag['textWidth'] + 6, $metricsText['textHeight'], 0, $employeeNumber);

                $image->resizeImage(120, 120, Imagick::FILTER_LANCZOS, 1);
                $image->rotateImage(new ImagickPixel('#00000000'), -20);
                $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 612, 203);

                $group->rotateImage(new ImagickPixel('#00000000'), -22);

                if (\strlen($employeeNumber) <= 1) {
                    $baseImage->compositeImage($group, Imagick::COMPOSITE_OVER, 660, 245);
                } else {
                    $baseImage->compositeImage($group, Imagick::COMPOSITE_OVER, 655, 247);
                }
            } elseif ($cardVariation === '2') {
                $group->newImage($groupWidth, $metricsText['textHeight'], '#00000000');
                $group->annotateImage($hashtag, 0, $metricsText['textHeight'], 0, '#');
                $group->annotateImage($text, $metricsHashtag['textWidth'] + 6, $metricsText['textHeight'], 0, $employeeNumber);

                $image->resizeImage(120, 120, Imagick::FILTER_LANCZOS, 1);
                $image->rotateImage(new ImagickPixel('#00000000'), 30);
                $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 715, 425);

                $group->rotateImage(new ImagickPixel('#00000000'), 32);

                if (\strlen($employeeNumber) <= 1) {
                    $baseImage->compositeImage($group, Imagick::COMPOSITE_OVER, 775, 465);
                } else {
                    $baseImage->compositeImage($group, Imagick::COMPOSITE_OVER, 767, 470);
                }
            } else {
                $group->newImage(300, 300, '#00000000');

                $hashtag->annotation(0, $metricsText['textHeight'], '#');
                $text->annotation($metricsHashtag['textWidth'] + 2, $metricsText['textHeight'], $employeeNumber);

                $group->drawImage($hashtag);
                $group->drawImage($text);

                $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 640, 293);

                if (\strlen($employeeNumber) <= 1) {
                    $baseImage->compositeImage($group, Imagick::COMPOSITE_OVER, 670, 317);
                } else {
                    $baseImage->compositeImage($group, Imagick::COMPOSITE_OVER, 663, 322);
                }
            }
        }

        if ($isContributor) {
            $file = $cardVariation === '3' ? 'contributor-skew.png' : 'contributor.png';
            $image = new Imagick(__DIR__ . '/../../../public/images/cards/cloud/' . $file);
            $image->setGravity(Imagick::GRAVITY_CENTER);

            if ($cardVariation === '1') {
                $image->resizeImage(120, 120, Imagick::FILTER_LANCZOS, 1);
                $image->rotateImage(new ImagickPixel('#00000000'), -20);
                $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 612, 203);
            } elseif ($cardVariation === '2') {
                $image->resizeImage(120, 120, Imagick::FILTER_LANCZOS, 1);
                $image->rotateImage(new ImagickPixel('#00000000'), 30);
                $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 715, 425);
            } else {
                $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 640, 293);
            }
        }

        if ($isHero) {
            $file = $cardVariation === '3' ? 'hero-skew.png' : 'hero.png';
            $image = new Imagick(__DIR__ . '/../../../public/images/cards/cloud/' . $file);
            $image->setGravity(Imagick::GRAVITY_CENTER);

            if ($cardVariation === '1') {
                $image->resizeImage(120, 120, Imagick::FILTER_LANCZOS, 1);
                $image->rotateImage(new ImagickPixel('#00000000'), -20);
                $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 612, 203);
            } elseif ($cardVariation === '2') {
                $image->resizeImage(120, 120, Imagick::FILTER_LANCZOS, 1);
                $image->rotateImage(new ImagickPixel('#00000000'), 30);
                $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 715, 425);
            } else {
                $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 640, 293);
            }
        }

        setlocale(LC_ALL, "en_US.utf8");
        // $name = \iconv("utf-8", "ascii//TRANSLIT", $name);
        // $memberSince = \iconv("utf-8", "ascii//TRANSLIT", $memberSince);
        // $githubName = \iconv("utf-8", "ascii//TRANSLIT", $githubName);

        $textName = new \ImagickDraw();
        $textName->setTextAlignment(Imagick::ALIGN_CENTER);
        $textName->setFont(__DIR__ . '/../../../public/fonts/Inter-Bold.ttf');
        $textName->setFillColor(new \ImagickPixel('#FFFFFF'));

        if (\strlen($name) > 32) {
            $name = \substr($name, 0, 32);
        }

        if ($cardVariation === '1') {
            if (\strlen($name) <= 23) {
                $scalingDown = false;
                $textName->setFontSize(54);
            } else {
                $scalingDown = true;
                $textName->setFontSize(36);
            }
        } elseif ($cardVariation === '2') {
            if (\strlen($name) <= 23) {
                $scalingDown = false;
                $textName->setFontSize(50);
            } else {
                $scalingDown = true;
                $textName->setFontSize(34);
            }
        } else {
            if (\strlen($name) <= 23) {
                $scalingDown = false;
                $textName->setFontSize(44);
            } else {
                $scalingDown = true;
                $textName->setFontSize(32);
            }
        }

        $textName->setFontWeight(700);

        $textMember = new \ImagickDraw();
        $textMember->setTextAlignment(Imagick::ALIGN_CENTER);
        $textMember->setFont(__DIR__ . '/../../../public/fonts/Inter-Medium.ttf');
        $textMember->setFillColor(new \ImagickPixel($isGolden || $isPlatinum ? '#FFFFFF' : '#FFB9CC'));
        $textMember->setFontWeight(500);
        $textMember->setTextKerning(1.12);

        if ($cardVariation === '1') {
            $textMember->setFontSize(21);

            $baseImage->annotateImage($textName, 550, 600, -22, $name);
            $baseImage->annotateImage($textMember, 585, 635, -22, $memberSince);
        } elseif ($cardVariation === '2') {
            $textMember->setFontSize(20);

            $baseImage->annotateImage($textName, 435, 590, 31.37, $name);
            $baseImage->annotateImage($textMember, 412, 628, 31.37, $memberSince);
        } else {
            $textMember->setFontSize(16);

            $textName->skewY(20);
            $textName->skewX(20);
            $textName->annotation(320, 700, $name);

            $textMember->skewY(20);
            $textMember->skewX(20);
            $textMember->annotation(330, 735, $memberSince);

            $baseImage->drawImage($textName);
            $baseImage->drawImage($textMember);
        }

        if (!empty($githubName)) {
            $text = new \ImagickDraw();
            $text->setTextAlignment(Imagick::ALIGN_LEFT);
            $text->setFont(__DIR__ . '/../../../public/fonts/Inter-Regular.ttf');
            $text->setFillColor(new \ImagickPixel('#FFFFFF'));
            $text->setFontSize($scalingDown ? 16 : 20);
            $text->setFontWeight(400);

            if ($cardVariation === '1') {
                $metrics = $baseImage->queryFontMetrics($text, $githubName);

                $group = new Imagick();
                $groupWidth = $metrics['textWidth'] + 32 + 4;
                $group->newImage($groupWidth, $metrics['textHeight'] + 10, '#00000000');
                $image = new Imagick(__DIR__ . '/../../../public/images/cards/cloud/github.png');
                $image->setGravity(Imagick::GRAVITY_CENTER);
                $image->resizeImage(32, 32, Imagick::FILTER_LANCZOS, 1);
                $precisionFix = -1;

                $group->compositeImage($image, Imagick::COMPOSITE_OVER, 0, 0);
                $group->annotateImage($text, 32 + 4, $metrics['textHeight'] - $precisionFix, 0, $githubName);

                $group->rotateImage(new ImagickPixel('#00000000'), -22);
                $x = 510 - $group->getImageWidth() / 2;
                $y = 530 - $group->getImageHeight() / 2;
                $baseImage->compositeImage($group, Imagick::COMPOSITE_OVER, $x, $y);
            } elseif ($cardVariation === '2') {
                $metrics = $baseImage->queryFontMetrics($text, $githubName);

                $group = new Imagick();
                $groupWidth = $metrics['textWidth'] + 32 + 4;
                $group->newImage($groupWidth, $metrics['textHeight'] + 10, '#00000000');
                $image = new Imagick(__DIR__ . '/../../../public/images/cards/cloud/github.png');
                $image->setGravity(Imagick::GRAVITY_CENTER);
                $image->resizeImage(32, 32, Imagick::FILTER_LANCZOS, 1);
                $precisionFix = -1;

                $group->compositeImage($image, Imagick::COMPOSITE_OVER, 0, 0);
                $group->annotateImage($text, 32 + 4, $metrics['textHeight'] - $precisionFix, 0, $githubName);

                $group->rotateImage(new ImagickPixel('#00000000'), 31.11);
                $x = 485 - $group->getImageWidth() / 2;
                $y = 530 - $group->getImageHeight() / 2;
                $baseImage->compositeImage($group, Imagick::COMPOSITE_OVER, $x, $y);
            } else {
                $text->skewY(20);
                $text->skewX(20);
                $text->setTextAlignment(\Imagick::ALIGN_CENTER);

                $text->annotation(320 + 15 + 2, 640, $githubName);
                $metrics = $baseImage->queryFontMetrics($text, $githubName);

                $image = new Imagick(__DIR__ . '/../../../public/images/cards/cloud/github-skew.png');
                $image->setGravity(Imagick::GRAVITY_CENTER);
                $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 512 - ($metrics['textWidth'] / 2), 518 + \strlen($githubName) * 1.3);

                $baseImage->drawImage($text);
            }
        }

        if (!empty($width) || !empty($height)) {
            $baseImage->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
        }

        $response
            ->addHeader('Cache-Control', 'private, max-age=3888000') // 45 days
            ->setContentType('image/png')
            ->file($baseImage->getImageBlob());
    });
