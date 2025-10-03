<?php

namespace Appwrite\Platform\Modules\Payments\Http\Webhooks\Provider;

use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\Utopia\Response;
use Appwrite\Payments\Provider\Registry;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Request;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createPaymentProviderWebhook';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/payments/webhooks/:providerId/:projectId')
            ->groups(['api', 'payments'])
            ->desc('Handle provider webhook')
            ->label('scope', 'public')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'providers',
                name: 'webhook',
                description: 'Handle provider webhook',
                auth: [],
                responses: []
            ))
            ->label('event', 'payments.providers.[providerId].webhook')
            ->label('audits.event', 'payments.webhook')
            ->label('audits.resource', 'provider/{request.providerId}')
            ->param('providerId', '', new UID(), 'Provider ID')
            ->param('projectId', '', new UID(), 'Project ID')
            ->inject('response')
            ->inject('request')
            ->inject('registryPayments')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(string $providerId, string $projectId, Response $response, Request $request, Registry $registryPayments, \Utopia\Database\Database $dbForPlatform, \Utopia\Database\Database $dbForProject)
    {
        $project = $dbForPlatform->getDocument('projects', $projectId);
        // Feature flag: ignore if disabled
        $paymentsCfg = (array) $project->getAttribute('payments', []);
        if (isset($paymentsCfg['enabled']) && $paymentsCfg['enabled'] === false) {
            $response->noContent();
            return;
        }
        $payments = (array) $project->getAttribute('payments', []);
        $providers = (array) ($payments['providers'] ?? []);
        $config = (array) ($providers[$providerId] ?? []);
        // Read raw payload and signature
        $payload = \file_get_contents('php://input') ?: '';
        $signature = (string) ($request->getHeader('stripe-signature') ?? '');
        $json = [];
        try { $json = \json_decode($payload, true) ?: []; } catch (\Throwable $e) { $json = []; }
        $json['_signature'] = $signature;
        $json['_raw'] = $payload;
        $registryPayments->get($providerId, $config, $project, $dbForPlatform, $dbForProject)->handleWebhook($json , new \Appwrite\Payments\Provider\ProviderState($providerId, $config, (array) ($config['state'] ?? [])));
        $response->noContent();
    }
}


