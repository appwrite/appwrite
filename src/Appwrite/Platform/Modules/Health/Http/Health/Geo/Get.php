<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\Geo;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;
use Utopia\System\System;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getGeo';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/health/geo')
            ->desc('Get geo')
            ->groups(['api', 'health'])
            ->label('scope', 'health.read')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Response $response): void
    {
        $geoEndpoint = System::getEnv('_APP_GEO_ENDPOINT', '');

        if (empty($geoEndpoint)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Geo service is not configured');
        }

        $checkStart = \microtime(true);

        try {
            $result = (new Client(new CurlAdapter()))
                ->withTimeout(3)
                ->withFollowRedirects(maxHops: 5)
                ->sendRequest((new RequestFactory())->createRequest(Method::GET, \rtrim($geoEndpoint, '/') . '/health'));

            if ($result->getStatusCode() !== 200) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Geo service returned status ' . $result->getStatusCode());
            }
        } catch (Exception $e) {
            throw $e;
        } catch (\Throwable $th) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Geo service is unreachable: ' . $th->getMessage());
        }

        $response->dynamic(new Document([
            'name' => 'geo',
            'status' => 'pass',
            'ping' => \round((\microtime(true) - $checkStart) * 1000),
        ]), Response::MODEL_HEALTH_STATUS);
    }
}
