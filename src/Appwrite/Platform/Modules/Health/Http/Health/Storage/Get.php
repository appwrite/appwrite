<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\Storage;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getStorage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/health/storage')
            ->desc('Get storage')
            ->groups(['api', 'health'])
            ->label('scope', 'health.read')
            ->label('sdk', new Method(
                namespace: 'health',
                group: 'storage',
                name: 'getStorage',
                description: '/docs/references/health/get-storage.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_HEALTH_STATUS,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->inject('response')
            ->inject('deviceForFiles')
            ->inject('deviceForFunctions')
            ->inject('deviceForSites')
            ->inject('deviceForBuilds')
            ->callback($this->action(...));
    }

    public function action(Response $response, Device $deviceForFiles, Device $deviceForFunctions, Device $deviceForSites, Device $deviceForBuilds): void
    {
        $devices = [$deviceForFiles, $deviceForFunctions, $deviceForSites, $deviceForBuilds];
        $checkStart = \microtime(true);

        foreach ($devices as $device) {
            $uniqueFileName = \uniqid('health', true);
            $filePath = $device->getPath($uniqueFileName);

            if (!$device->write($filePath, 'test', 'text/plain')) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed writing test file to ' . $device->getRoot());
            }

            if ($device->read($filePath) !== 'test') {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed reading test file from ' . $device->getRoot());
            }

            if (!$device->delete($filePath)) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed deleting test file from ' . $device->getRoot());
            }
        }

        $response->dynamic(new Document([
            'status' => 'pass',
            'ping' => \round((\microtime(true) - $checkStart) * 1000),
        ]), Response::MODEL_HEALTH_STATUS);
    }
}
