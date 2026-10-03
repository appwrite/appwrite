<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Previews;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Image\Image;
use Utopia\Platform\Action;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;

class Get extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getPreview';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/videos/:videoId/previews/:previewId')
            ->desc('Get preview')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.read')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('cache', true)
            ->label('cache.resourceType', 'video/{request.videoId}')
            ->label('cache.resource', 'preview/{request.previewId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'videos',
                name: 'getPreview',
                description: '/docs/references/videos/get-preview.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::IMAGE,
                type: MethodType::LOCATION,
                locationAuth: ['Project', 'ImpersonateUserId'],
            ))
            ->param('videoId', '', new UID(), 'Video unique ID.')
            ->param('previewId', '', new UID(), 'Preview unique ID.')
            ->param('width', 0, new Range(0, 4000), 'Resize preview image width, in pixels.', true)
            ->param('height', 0, new Range(0, 4000), 'Resize preview image height, in pixels.', true)
            ->param('output', '', new WhiteList(\array_keys(Config::getParam('storage-outputs')), true), 'Output format type (jpeg, jpg, png, gif and webp).', true, enum: new Enum(name: 'ImageFormat'))
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('authorization')
            ->inject('deviceForVideos')
            ->callback($this->action(...));
    }

    public function action(
        string $videoId,
        string $previewId,
        int $width,
        int $height,
        string $output,
        Request $request,
        Response $response,
        Database $dbForProject,
        User $user,
        Authorization $authorization,
        Device $deviceForVideos
    ): void {
        if (!\extension_loaded('imagick')) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Imagick extension is missing');
        }

        $video = $this->getReadableVideo($dbForProject, $authorization, $user, $videoId);

        $preview = $authorization->skip(fn () => $dbForProject->getDocument('videos_previews', $previewId));

        if ($preview->isEmpty() || $preview->getAttribute('videoInternalId') !== $video->getSequence()) {
            throw new Exception(Exception::VIDEO_PREVIEW_NOT_FOUND);
        }

        $path = $preview->getAttribute('path', '');

        if (empty($path) || !$deviceForVideos->exists($path)) {
            throw new Exception(Exception::VIDEO_PREVIEW_NOT_FOUND);
        }

        $outputs = Config::getParam('storage-outputs');

        if (empty($output)) {
            // Sprite sheets are written as JPEG; only upgrade to webp when the client
            // actually advertises support for it.
            $output = \str_contains($request->getHeaderLine('accept'), 'image/webp') ? 'webp' : 'jpg';
        }

        if (!\array_key_exists($output, $outputs)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Unsupported output format');
        }

        $source = (string) $deviceForVideos->read($path);

        if ($width > 0 || $height > 0) {
            $image = new Image($source);
            $image->crop($width, $height, Image::GRAVITY_CENTER);
            $source = $image->output($output, 100);
        }

        $response
            ->setContentType($outputs[$output])
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + (60 * 60 * 24 * 30)) . ' GMT') // 30 days
            ->addHeader('Cache-Control', 'private, max-age=2592000')
            ->addHeader('X-Peak', \memory_get_peak_usage())
            ->file($source);
    }
}
