<?php

namespace Appwrite\Platform\Modules\Avatars\Http;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action as PlatformAction;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Image\Canvas;
use Utopia\Image\Format;
use Utopia\Image\Transform;

class Action extends PlatformAction
{
    protected function getAppRoot(): string
    {
        return \dirname(__DIR__, 6);
    }

    protected function avatar(string $type, string $code, int $width, int $height, int $quality, Response $response): void
    {
        $code = \strtolower($code);
        $type = \strtolower($type);
        $set = Config::getParam('avatar-' . $type, []);

        if (empty($set)) {
            throw new Exception(Exception::AVATAR_SET_NOT_FOUND);
        }

        if (!\array_key_exists($code, $set)) {
            throw new Exception(Exception::AVATAR_NOT_FOUND);
        }

        $path = $set[$code]['path'];

        if (!\is_readable($path)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'File not readable in ' . $path);
        }

        $data = (new Canvas((string) \file_get_contents($path), Config::getParam('storage-image-limits')))
            ->apply(new Transform(width: $width, height: $height))
            ->encode(Format::Png, $quality === -1 ? null : $quality);
        $response
            ->addHeader('Cache-Control', 'private, max-age=2592000') // 30 days
            ->setContentType('image/png')
            ->file($data);
    }
}
