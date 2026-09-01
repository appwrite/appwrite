<?php

declare(strict_types=1);

namespace Appwrite\Platform\Modules\Proxy\Http\SMTP\Files;

use Appwrite\Platform\Action;
use Appwrite\Smtp\Gateway;
use Appwrite\Smtp\Storage;
use Appwrite\Utopia\Response;
use InvalidArgumentException;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

final class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getSMTPFile';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/smtp/deliveries/:deliveryId/files/:fileId')
            ->groups(['api'])
            ->label('scope', 'public')
            ->param('deliveryId', '', new Text(128), 'SMTP delivery identifier.')
            ->param('fileId', '', new Text(128), 'SMTP file identifier.')
            ->param('token', '', new Text(4096), 'Short-lived SMTP file token.')
            ->inject('response')
            ->inject('storageForSmtp')
            ->callback($this->action(...));
    }

    public function action(
        string $deliveryId,
        string $fileId,
        string $token,
        Response $response,
        Storage $storage,
    ): void {
        try {
            $claims = Gateway::downloadTokens()->verify($token);
        } catch (InvalidArgumentException) {
            $response->setStatusCode(Response::STATUS_CODE_UNAUTHORIZED)->json(['error' => 'Invalid or expired SMTP file token.']);

            return;
        }

        if (($claims['deliveryId'] ?? null) !== $deliveryId || ($claims['fileId'] ?? null) !== $fileId) {
            $response->setStatusCode(Response::STATUS_CODE_UNAUTHORIZED)->json(['error' => 'SMTP file token mismatch.']);

            return;
        }

        $projectId = $claims['projectId'] ?? null;
        $path = $claims['path'] ?? null;
        if (! is_string($projectId) || $projectId === '' || ! is_string($path)) {
            $response->setStatusCode(Response::STATUS_CODE_UNAUTHORIZED)->json(['error' => 'Invalid SMTP file token.']);

            return;
        }

        $device = $storage->getDevice($projectId);
        $prefix = rtrim($device->getPath("smtp/{$deliveryId}"), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (! str_starts_with($path, $prefix) || ! $device->exists($path)) {
            $response->setStatusCode(Response::STATUS_CODE_NOT_FOUND)->json(['error' => 'SMTP file not found.']);

            return;
        }

        $contentType = is_string($claims['contentType'] ?? null)
            ? $claims['contentType']
            : 'application/octet-stream';
        $filename = is_string($claims['filename'] ?? null)
            ? preg_replace('/[^a-zA-Z0-9._-]/', '_', $claims['filename'])
            : 'attachment';

        $response
            ->setContentType($contentType)
            ->addHeader('Cache-Control', 'private, no-store')
            ->addHeader('Content-Disposition', 'attachment; filename="'.$filename.'"')
            ->send((string) $device->read($path));
    }
}
