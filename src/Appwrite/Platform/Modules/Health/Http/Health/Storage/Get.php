<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\Storage;

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
            $path = $device->getPath(\uniqid('health', true));

            try {
                if (!$device->write($path, 'test', 'text/plain')) {
                    throw new \Exception("Failed writing test file to {$device->getRoot()}");
                }

                $content = $device->read($path);
                if ($content !== 'test') {
                    throw new \Exception("Failed reading test file from {$device->getRoot()}: content mismatch");
                }
            } finally {
                try {
                    $device->delete($path);
                } catch (\Throwable) {
                }
            }
        }

        $response->dynamic(new Document([
            'status' => 'pass',
            'ping' => \round((\microtime(true) - $checkStart) * 1000),
        ]), Response::MODEL_HEALTH_STATUS);
    }
}
