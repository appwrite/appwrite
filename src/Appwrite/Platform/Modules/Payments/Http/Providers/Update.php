<?php

namespace Appwrite\Platform\Modules\Payments\Http\Providers;

use Appwrite\Payments\Provider\Registry;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\JSON as JSONValidator;

class Update extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'updatePaymentProviders';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/payments/providers')
            ->groups(['api', 'payments'])
            ->desc('Configure payments providers')
            ->label('scope', 'payments.write')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('event', 'payments.providers.update')
            ->label('audits.event', 'payments.providers.update')
            ->label('audits.resource', 'payments/providers')
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'providers',
                name: 'update',
                description: 'Configure payments providers',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ANY,
                    )
                ]
            ))
            ->param('config', [], new JSONValidator(), 'Payments configuration JSON', false)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('registryPayments')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(array $config, Response $response, Database $dbForPlatform, Database $dbForProject, Registry $registryPayments, Document $project)
    {
        $projectDoc = $dbForPlatform->getDocument('projects', $project->getId());
        $existing = (array) $projectDoc->getAttribute('payments', []);
        $providers = (array) ($config['providers'] ?? []);

        // Basic validation + test connection for known providers
        foreach ($providers as $providerId => $providerConfig) {
            if ($providerId === 'stripe') {
                $secret = (string) ($providerConfig['secretKey'] ?? '');
                if ($secret === '') {
                    $response->setStatusCode(400);
                    $response->json(['message' => 'Stripe secretKey is required']);
                    return;
                }
            }
        }

        foreach ($providers as $providerId => $providerConfig) {
            $adapter = $registryPayments->get($providerId, (array) $providerConfig, $project, $dbForPlatform, $dbForProject);
            $test = $adapter->testConnection((array) $providerConfig);
            if (!$test->success) {
                $response->setStatusCode(400);
                $response->json(['message' => 'Provider test failed: ' . $test->message]);
                return;
            }
            $state = $adapter->configure((array) $providerConfig, $project);
            $providers[$providerId] = array_merge((array) $providerConfig, [
                'state' => $state->metadata,
            ]);
        }

        // Feature flag gating
        $enabled = (bool) ($config['enabled'] ?? true);
        $merged = [
            'providers' => array_merge((array) ($existing['providers'] ?? []), $providers),
            'defaults' => $config['defaults'] ?? ($existing['defaults'] ?? []),
            'enabled' => $enabled,
        ];

        $projectDoc->setAttribute('payments', $merged);
        $updated = $dbForPlatform->updateDocument('projects', $projectDoc->getId(), $projectDoc);
        $out = (array) $updated->getAttribute('payments', []);
        $prov = (array) ($out['providers'] ?? []);
        foreach ($prov as $pid => &$cfg) {
            if (isset($cfg['secretKey'])) {
                $cfg['secretKey'] = '***';
            }
            if (isset($cfg['webhookSecret'])) {
                $cfg['webhookSecret'] = '***';
            }
        }
        $out['providers'] = $prov;
        $response->json(['payments' => $out]);
    }
}
