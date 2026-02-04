<?php

namespace Appwrite\Platform\Modules\Storage\Http\Buckets\Files\Preview;

use Appwrite\Extend\Exception;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Database\Validator\UID;
use Utopia\Image\Image;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Compression\Algorithms\GZIP;
use Utopia\Storage\Compression\Algorithms\Zstd;
use Utopia\Storage\Compression\Compression;
use Utopia\Storage\Device;
use Utopia\Swoole\Request;
use Utopia\System\System;
use Utopia\Validator\HexColor;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

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
            ->label('resourceType', RESOURCE_TYPE_BUCKETS)
            ->label('cache', true)
            ->label('cache.resourceType', 'bucket/{request.bucketId}')
            ->label('cache.resource', 'file/{request.fileId}')
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
                contentType: ContentType::IMAGE
            ))
            ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](https://appwrite.io/docs/server/storage#createBucket).')
            ->param('fileId', '', new UID(), 'File ID')
            ->param('width', 0, new Range(0, 4000), 'Resize preview image width, Pass an integer between 0 to 4000.', true)
            ->param('height', 0, new Range(0, 4000), 'Resize preview image height, Pass an integer between 0 to 4000.', true)
            ->param('gravity', Image::GRAVITY_CENTER, new WhiteList(Image::getGravityTypes()), 'Image crop gravity. Can be one of ' . implode(",", Image::getGravityTypes()), true)
            ->param('quality', -1, new Range(-1, 100), 'Preview image quality. Pass an integer between 0 to 100. Defaults to keep existing image quality.', true)
            ->param('borderWidth', 0, new Range(0, 100), 'Preview image border in pixels. Pass an integer between 0 to 100. Defaults to 0.', true)
            ->param('borderColor', '', new HexColor(), 'Preview image border color. Use a valid HEX color, no # is needed for prefix.', true)
            ->param('borderRadius', 0, new Range(0, 4000), 'Preview image border radius in pixels. Pass an integer between 0 to 4000.', true)
            ->param('opacity', 1, new Range(0, 1, Range::TYPE_FLOAT), 'Preview image opacity. Only works with images having an alpha channel (like png). Pass a number between 0 to 1.', true)
            ->param('rotation', 0, new Range(-360, 360), 'Preview image rotation in degrees. Pass an integer between -360 and 360.', true)
            ->param('background', '', new HexColor(), 'Preview image background color. Only works with transparent images (png). Use a valid HEX color, no # is needed for prefix.', true)
            ->param('output', '', new WhiteList(\array_keys(Config::getParam('storage-outputs')), true), 'Output format type (jpeg, jpg, png, gif and webp).', true)
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
        Authorization $authorization
    ) {

        if (!\extension_loaded('imagick')) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Imagick extension is missing');
        }

        /* @type Document $bucket */
        $bucket = $authorization->skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        if (!$bucket->getAttribute('transformations', true) && !$isAPIKey && !$isPrivilegedUser) {
            throw new Exception(Exception::STORAGE_BUCKET_TRANSFORMATIONS_DISABLED);
        }

        $isToken = !$resourceToken->isEmpty() && $resourceToken->getAttribute('bucketInternalId') === $bucket->getSequence();
        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        $valid = $authorization->isValid(new Input(Database::PERMISSION_READ, $bucket->getRead()));
        if (!$fileSecurity && !$valid && !$isToken) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }

        if ($fileSecurity && !$valid && !$isToken) {
            $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
        } else {
            /* @type Document $file */
            $file = $authorization->skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));
        }

        if (!$resourceToken->isEmpty() && $resourceToken->getAttribute('fileInternalId') !== $file->getSequence()) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        if ($file->isEmpty()) {
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
        if (!\in_array($mime, $inputs) || $file->getAttribute('sizeActual') > (int) System::getEnv('_APP_STORAGE_PREVIEW_LIMIT', APP_STORAGE_READ_BUFFER)) {
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

        if (empty($output)) {
            // when file extension is provided but it's not one of our
            // supported outputs we fallback to `jpg`
            if (!empty($type) && !array_key_exists($type, $outputs)) {
                $type = 'jpg';
            }

            // when file extension is not provided and the mime type is not one of our supported outputs
            // we fallback to `jpg` output format
            $output = empty($type) ? (array_search($mime, $outputs) ?? 'jpg') : $type;
        }

        $startTime = \microtime(true);

        $source = $deviceForFiles->read($path);

        $downloadTime = \microtime(true) - $startTime;

        if (!empty($cipher)) { // Decrypt
            $source = OpenSSL::decrypt(
                $source,
                $file->getAttribute('openSSLCipher'),
                System::getEnv('_APP_OPENSSL_KEY_V' . $file->getAttribute('openSSLVersion')),
                0,
                \hex2bin($file->getAttribute('openSSLIV')),
                \hex2bin($file->getAttribute('openSSLTag'))
            );
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

        try {
            $image = new Image($source);
        } catch (\Exception $e) {
            throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED, $e->getMessage());
        }

        $image->crop((int) $width, (int) $height, $gravity);

        if (!empty($opacity) || $opacity === 0) {
            $image->setOpacity($opacity);
        }

        if (!empty($background)) {
            $image->setBackground('#' . $background);
        }

        if (!empty($borderWidth)) {
            $image->setBorder($borderWidth, '#' . $borderColor);
        }

        if (!empty($borderRadius)) {
            $image->setBorderRadius($borderRadius);
        }

        if (!empty($rotation)) {
            $image->setRotation(($rotation + 360) % 360);
        }

        $data = $image->output($output, $quality);

        $renderingTime = \microtime(true) - $startTime - $downloadTime - $decryptionTime - $decompressionTime;

        $totalTime = \microtime(true) - $startTime;

        Console::info("File preview rendered,project=" . $project->getId() . ",bucket=" . $bucketId . ",file=" . $file->getId() . ",uri=" . $request->getURI() . ",total=" . $totalTime . ",rendering=" . $renderingTime . ",decryption=" . $decryptionTime . ",decompression=" . $decompressionTime . ",download=" . $downloadTime);

        $contentType = (\array_key_exists($output, $outputs)) ? $outputs[$output] : $outputs['jpg'];

        //Do not update transformedAt if it's a console user
        if (!User::isPrivileged($authorization->getRoles())) {
            $transformedAt = $file->getAttribute('transformedAt', '');
            if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_PROJECT_ACCESS)) > $transformedAt) {
                $file->setAttribute('transformedAt', DateTime::now());
                $authorization->skip(fn () => $dbForProject->updateDocument('bucket_' . $file->getAttribute('bucketInternalId'), $file->getId(), $file));
            }
        }

        $response
            ->addHeader('Cache-Control', 'private, max-age=2592000') // 30 days
            ->setContentType($contentType)
            ->file($data);

        unset($image);
    }
}
