<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\AntiVirus;

use Appwrite\ClamAV\Network;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getAntivirus';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/health/anti-virus')
            ->desc('Get antivirus')
            ->groups(['api', 'health'])
            ->label('scope', 'health.read')
            ->label('sdk', new Method(
                namespace: 'health',
                group: 'health',
                name: 'getAntivirus',
                description: '/docs/references/health/get-storage-anti-virus.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_HEALTH_ANTIVIRUS,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Response $response): void
    {
        $output = [
            'status' => '',
            'version' => '',
        ];

        if (System::getEnv('_APP_STORAGE_ANTIVIRUS') === 'disabled') {
            $output['status'] = 'disabled';
            $output['version'] = '';
        } else {
            $antivirus = new Network(
                System::getEnv('_APP_STORAGE_ANTIVIRUS_HOST', 'clamav'),
                (int) System::getEnv('_APP_STORAGE_ANTIVIRUS_PORT', 3310)
            );

            try {
                $output['version'] = @$antivirus->version();
                $output['status'] = (@$antivirus->ping()) ? 'pass' : 'fail';
            } catch (\Throwable) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Antivirus is not available');
            }
        }

        $response->dynamic(new Document($output), Response::MODEL_HEALTH_ANTIVIRUS);
    }
}
