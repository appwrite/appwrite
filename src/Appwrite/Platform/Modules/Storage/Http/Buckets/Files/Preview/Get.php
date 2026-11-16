<?php

namespace Appwrite\Platform\Modules\Storage\Http\Buckets\Files\Preview;

use Appwrite\Abuse\Limit;
use Appwrite\Autogravity\Detector as AutogravityDetector;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Storage\Config\CacheControl;
use Appwrite\Platform\Modules\Storage\Config\StorageCacheControl;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Storage\Encryption;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Compression\Algorithms\GZIP;
use Utopia\Compression\Algorithms\Zstd;
use Utopia\Compression\Compression;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\PermissionType;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Database\Validator\UID;
use Utopia\Image\Border;
use Utopia\Image\Canvas;
use Utopia\Image\Exception\ImageException;
use Utopia\Image\Exception\LimitExceededException;
use Utopia\Image\Format;
use Utopia\Image\Gravity;
use Utopia\Image\Point;
use Utopia\Image\Transform;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Span\Span;
use Utopia\Storage\Device;
use Utopia\System\System;
use Utopia\Validator\HexColor;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

    private const GRAVITY_AUTO = 'auto';


    public static function getName()
    {
        return 'getFilePreview';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/storage/buckets/:bucketId/files/:fileId/preview')
            ->desc('Get file preview')
            ->groups(['api', 'storage'])
            ->label('scope', 'files.read')
            ->label('usage.resource', 'bucket/{request.bucketId}/file/{request.fileId}')
            ->label('resourceType', RESOURCE_TYPE_BUCKETS)
            ->label('abuse', [Limit::attempts(['ip', 'method', 'url', 'userId', 'param.fileId'], 60, seconds: 60)])
            ->label('cache', true)
            ->label('cache.resourceType', 'bucket/{request.bucketId}')
            ->label('cache.resource', 'file/{request.fileId}')
            ->label('cache.params', ['width', 'height', 'gravity', 'quality', 'borderWidth', 'borderColor', 'borderRadius', 'opacity', 'rotation', 'background', 'output', 'project'])
            ->label('cache.negotiate', ['output' => Config::getParam('storage-outputs-auto')])
            ->label('sdk', new Method(
                namespace: 'storage',
                group: 'files',
                name: 'getFilePreview',
                description: '/docs/references/storage/get-file-preview.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NONE
                    )
                ],
                type: MethodType::LOCATION,
                locationAuth: ['Project', 'Environment', 'ImpersonateUserId'],
                contentType: ContentType::IMAGE
            ))
            ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](https://appwrite.io/docs/server/storage#createBucket).')
            ->param('fileId', '', new UID(), 'File ID')
            ->param('width', 0, new Range(0, 4000), 'Resize preview image width, Pass an integer between 0 to 4000.', true)
            ->param('height', 0, new Range(0, 4000), 'Resize preview image height, Pass an integer between 0 to 4000.', true)
            ->param('gravity', Gravity::Center->value, new WhiteList([self::GRAVITY_AUTO, ...\array_column(Gravity::cases(), 'value')]), 'Image crop gravity. Can be one of ' . implode(",", [self::GRAVITY_AUTO, ...\array_column(Gravity::cases(), 'value')]), true, enum: new Enum(name: 'ImageGravity'))
            ->param('quality', -1, new Range(-1, 100), 'Preview image quality. Pass an integer between 0 to 100. Defaults to keep existing image quality.', true)
            ->param('borderWidth', 0, new Range(0, 100), 'Preview image border in pixels. Pass an integer between 0 to 100. Defaults to 0.', true)
            ->param('borderColor', '', new HexColor(), 'Preview image border color. Use a valid HEX color, no # is needed for prefix.', true)
            ->param('borderRadius', 0, new Range(0, 4000), 'Preview image border radius in pixels. Pass an integer between 0 to 4000.', true)
            ->param('opacity', 1, new Range(0, 1, Range::TYPE_FLOAT), 'Preview image opacity. Only works with images having an alpha channel (like png). Pass a number between 0 to 1.', true)
            ->param('rotation', 0, new Range(-360, 360), 'Preview image rotation in degrees. Pass an integer between -360 and 360.', true)
            ->param('background', '', new HexColor(), 'Preview image background color. Only works with transparent images (png). Use a valid HEX color, no # is needed for prefix.', true)
            ->param('output', '', new WhiteList([...\array_keys(Config::getParam('storage-outputs')), Request::NEGOTIATE_AUTO], true), 'Output format type (jpeg, jpg, png, gif, webp, heic and avif). Pass auto to serve avif or webp when the client\'s Accept header supports it, falling back to the original format.', true, enum: new Enum(name: 'ImageFormat'))
            // NOTE: this is only for the sdk generator and is not used in the action below and is utilised in `resources.php` for `resourceToken`.
            ->param('token', '', new Text(512), 'File token for accessing this file.', true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('resourceToken')
            ->inject('deviceForFiles')
            ->inject('deviceForLocal')
            ->inject('project')
            ->inject('authorization')
            ->inject('user')
            ->inject('cacheControlForStorage')
            ->inject('autogravity')
            ->callback($this->action(...));
    }

    public function action(
        string $bucketId,
        string $fileId,
        int $width,
        int $height,
        string $gravity,
        int $quality,
        int $borderWidth,
        string $borderColor,
        int $borderRadius,
        float $opacity,
        int $rotation,
        string $background,
        string $output,
        ?string $token,
        Request $request,
        Response $response,
        Database $dbForProject,
        Document $resourceToken,
        Device $deviceForFiles,
        Device $deviceForLocal,
        Document $project,
        Authorization $authorization,
        User $user,
        callable $cacheControlForStorage,
        AutogravityDetector $autogravity
    ) {

        $bucket = $authorization->skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        $isAPIKey = $user->isKey($authorization->getRoles());
        $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        if (!$bucket->getAttribute('transformations', true) && !$isAPIKey && !$isPrivilegedUser) {
            throw new Exception(Exception::STORAGE_BUCKET_TRANSFORMATIONS_DISABLED);
        }

        $isToken = !$resourceToken->isEmpty() && $resourceToken->getAttribute('bucketInternalId') === $bucket->getSequence();
        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        $valid = $authorization->isValid(new Input(PermissionType::Read, $bucket->getPermissionsByType(PermissionType::Read)));
        if (!$fileSecurity && !$valid && !$isToken) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }

        if ($fileSecurity && !$valid && !$isToken) {
            $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
        } else {
            $file = $authorization->skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));
        }

        if (!$resourceToken->isEmpty() && $resourceToken->getAttribute('fileInternalId') !== $file->getSequence()) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        // A file stays incomplete until its final chunk is joined and scanned.
        if ($file->isEmpty() || $file->getAttribute('chunksUploaded', 0) < $file->getAttribute('chunksTotal', 1)) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        $inputs = Config::getParam('storage-inputs');
        $outputs = Config::getParam('storage-outputs');
        $fileLogos = Config::getParam('storage-logos');

        $path = $file->getAttribute('path');
        $type = \strtolower(\pathinfo($path, PATHINFO_EXTENSION));
        $algorithm = $file->getAttribute('algorithm', Compression::NONE);
        $cipher = $file->getAttribute('openSSLCipher');
        $mime = $file->getAttribute('mimeType');
        if (!\in_array($mime, $inputs) || $file->getAttribute('sizeOriginal') > (int) System::getEnv('_APP_STORAGE_PREVIEW_LIMIT', APP_STORAGE_READ_BUFFER)) {
            if (!\in_array($mime, $inputs)) {
                $path = (\array_key_exists($mime, $fileLogos)) ? $fileLogos[$mime] : $fileLogos['default'];
            } else {
                // it was an image but the file size exceeded the limit
                $path = $fileLogos['default_image'];
            }

            $algorithm = Compression::NONE;
            $cipher = null;
            $background = (empty($background)) ? 'eceff1' : $background;
            $type = \strtolower(\pathinfo($path, PATHINFO_EXTENSION));
            $deviceForFiles = $deviceForLocal;
        }

        if (!$deviceForFiles->exists($path)) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        $negotiated = $output === Request::NEGOTIATE_AUTO;
        if ($negotiated) {
            // resolved the same way as the cache key, so cached and fresh responses match
            $output = $request->getNegotiatedParams()['output'] ?? '';
        }

        if (empty($output)) {
            // when file extension is provided but it's not one of our
            // supported outputs we fallback to `jpg`
            if (!empty($type) && !array_key_exists($type, $outputs)) {
                $type = 'jpg';
            }

            // when file extension is not provided and the mime type is not one of our supported outputs
            // we fallback to `jpg` output format
            $output = empty($type) ? (array_search($mime, $outputs) ?: 'jpg') : $type;
        }

        $startTime = \microtime(true);

        $source = (string) $deviceForFiles->read($path);

        $downloadTime = \microtime(true) - $startTime;

        if (!empty($cipher)) { // Decrypt
            $source = (new Encryption($file))->decrypt($source);
        }

        $decryptionTime = \microtime(true) - $startTime - $downloadTime;

        switch ($algorithm) {
            case Compression::ZSTD:
                $compressor = new Zstd();
                $source = $compressor->decompress($source);
                break;
            case Compression::GZIP:
                $compressor = new GZIP();
                $source = $compressor->decompress($source);
                break;
        }

        $decompressionTime = \microtime(true) - $startTime - $downloadTime - $decryptionTime;

        $resolutionExceeded = function (LimitExceededException $e) use ($source): Exception {
            [$sourceWidth, $sourceHeight] = \getimagesizefromstring($source) ?: [0, 0];

            return new Exception(
                Exception::STORAGE_IMAGE_RESOLUTION_EXCEEDED,
                \sprintf('Image resolution %dx%d exceeds the maximum allowed for processing', $sourceWidth, $sourceHeight),
                previous: $e
            );
        };

        // Reads the header only; the pixels are decoded once, by encode() below.
        try {
            $image = new Canvas($source, Config::getParam('storage-image-limits'));
        } catch (LimitExceededException $e) {
            throw $resolutionExceeded($e);
        } catch (ImageException $e) {
            throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED, $e->getMessage());
        }

        if ($gravity === self::GRAVITY_AUTO && !$autogravity->isEnabled()) {
            throw new Exception(
                Exception::GENERAL_ARGUMENT_INVALID,
                'Autogravity needs to be configured with _APP_AUTOGRAVITY_HOST to use automatic gravity'
            );
        }

        $focus = null;
        if (
            $gravity === self::GRAVITY_AUTO
            && $width > 0
            && $height > 0
            && \abs($width / $height - $image->width / $image->height) > 0.000001
        ) {
            $detected = $autogravity
                ->get($source)
                ->unrotate(match ($image->orientation) {
                    3 => 180,
                    6 => 90,
                    8 => -90,
                    default => 0,
                });
            $focus = new Point($detected->x, $detected->y);
        }
        if ($gravity === self::GRAVITY_AUTO) {
            $gravity = Gravity::Center->value;
        }

        if ($width > 0 || $height > 0 || $gravity !== Gravity::Center->value) {
            Span::add('storage.transform.crop.width', $width);
            Span::add('storage.transform.crop.height', $height);
            Span::add('storage.transform.crop.gravity', $gravity);
        }
        if ($opacity !== 1.0) {
            Span::add('storage.transform.opacity', $opacity);
        }
        if (!empty($background)) {
            Span::add('storage.transform.background', $background);
        }
        if ($borderWidth > 0) {
            Span::add('storage.transform.border.width', $borderWidth);
            Span::add('storage.transform.border.color', $borderColor);
        }
        if ($borderRadius > 0) {
            Span::add('storage.transform.borderRadius', $borderRadius);
        }
        if ($rotation !== 0) {
            Span::add('storage.transform.rotation', $rotation);
        }
        if ($quality !== -1) {
            Span::add('storage.transform.quality', $quality);
        }

        try {
            $data = $image
                ->apply(new Transform(
                    width: $width,
                    height: $height,
                    gravity: Gravity::from($gravity),
                    focus: $focus,
                    opacity: $opacity,
                    background: empty($background) ? null : '#' . $background,
                    border: $borderWidth > 0 ? new Border($borderWidth, '#' . $borderColor) : null,
                    radius: $borderRadius,
                    rotation: ($rotation + 360) % 360,
                ))
                ->encode(Format::from($output === 'jpeg' ? 'jpg' : $output), $quality === -1 ? null : $quality);
        } catch (LimitExceededException $e) {
            throw $resolutionExceeded($e);
        } catch (ImageException $e) {
            // The pixel data is damaged, or this build has no encoder for the format (HEIC).
            throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED, $e->getMessage());
        }

        $renderingTime = \microtime(true) - $startTime - $downloadTime - $decryptionTime - $decompressionTime;

        $totalTime = \microtime(true) - $startTime;

        Span::add('storage.file.id', $file->getId());
        Span::add('storage.bucket.id', $bucketId);
        Span::add('storage.file.size_bytes', $file->getAttribute('sizeActual'));
        if (!empty($type)) {
            Span::add('storage.file.extension', $type);
        }
        Span::add('storage.timing.download_seconds', $downloadTime);
        Span::add('storage.timing.decryption_seconds', $decryptionTime);
        Span::add('storage.timing.decompression_seconds', $decompressionTime);
        Span::add('storage.timing.rendering_seconds', $renderingTime);
        Span::add('storage.timing.total_seconds', $totalTime);

        $contentType = (\array_key_exists($output, $outputs)) ? $outputs[$output] : $outputs['jpg'];

        //Do not update transformedAt if it's a console user
        if (!$user->isPrivileged($authorization->getRoles())) {
            $transformedAt = $file->getAttribute('transformedAt', '');
            if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_PROJECT_ACCESS)) > $transformedAt) {
                $file->setAttribute('transformedAt', DateTime::now());
                $authorization->skip(fn () => $dbForProject->updateDocument('bucket_' . $file->getAttribute('bucketInternalId'), $file->getId(), new Document([
                    'transformedAt' => $file->getAttribute('transformedAt'),
                ])));
            }
        }

        $maxAge = 2592000; // 30 days
        $cacheControl = $cacheControlForStorage(new StorageCacheControl(
            source: CacheControl::SOURCE_ACTION,
            user: $user,
            maxAge: $maxAge,
            project: $project,
            bucket: $bucket,
            file: $file,
            resourceToken: $resourceToken,
            fileSecurity: $fileSecurity,
            negotiated: $negotiated,
        ));

        if ($negotiated) {
            $response->addHeader('Vary', 'Accept');
        }

        $response
            ->addHeader('Cache-Control', $cacheControl)
            ->setContentType($contentType)
            ->file($data);

        unset($image);
    }
}
