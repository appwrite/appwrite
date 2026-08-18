<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\AntiVirus;

use Appwrite\Antivirus\Client as Antivirus;
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
            ->inject('response')
            ->inject('antivirus')
            ->callback($this->action(...));
    }

    public function action(Response $response, Antivirus $antivirus): void
    {
        $output = [
            'status' => '',
            'version' => '',
        ];

        if (System::getEnv('_APP_STORAGE_ANTIVIRUS') === 'disabled') {
            $output['status'] = 'disabled';
            $output['version'] = '';
        } else {
            $output['version'] = $antivirus->version();
            $output['status'] = $antivirus->ping() ? 'pass' : 'fail';
        }

        $response->dynamic(new Document($output), Response::MODEL_HEALTH_ANTIVIRUS);
    }
}
