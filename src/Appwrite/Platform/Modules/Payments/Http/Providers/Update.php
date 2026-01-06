<?php

namespace Appwrite\Platform\Modules\Payments\Http\Providers;

use Appwrite\Event\Event;
use Appwrite\Payments\Provider\ProviderPlanRef;
use Appwrite\Payments\Provider\ProviderState;
use Appwrite\Payments\Provider\Registry;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
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
                description: <<<EOT
                Configure payment providers for the project. Currently supports Stripe.
                EOT,
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PAYMENT_PROVIDER_CONFIG,
                    )
                ]
            ))
            ->param('config', [], new JSONValidator(), 'Payments configuration JSON', false)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('registryPayments')
            ->inject('project')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(array $config, Response $response, Database $dbForPlatform, Database $dbForProject, Registry $registryPayments, Document $project, Event $queueForEvents)
    {
        $projectDoc = $dbForPlatform->getDocument('projects', $project->getId());
        $existing = (array) $projectDoc->getAttribute('payments', []);
        $existingProviders = (array) ($existing['providers'] ?? []);
        $providers = (array) ($config['providers'] ?? []);

        $providerKeys = \array_keys($providers);
        $queueForEvents->setParam('providers', empty($providerKeys) ? 'providers' : \implode(',', $providerKeys));

        // Basic validation + test connection for known providers
        foreach ($providers as $providerId => $providerConfig) {
            if ($providerId === 'stripe') {
                $secret = (string) ($providerConfig['secretKey'] ?? '');
                if ($secret === '') {
                    throw new \Appwrite\Extend\Exception(\Appwrite\Extend\Exception::GENERAL_BAD_REQUEST, 'Stripe secretKey is required');
                }
            }

            // Check if provider is already configured - prevent re-setup without disconnect
            if (isset($existingProviders[$providerId])) {
                $existingState = (array) ($existingProviders[$providerId]['state'] ?? []);
                $existingWebhookId = (string) ($existingState['webhookEndpointId'] ?? '');
                if ($existingWebhookId !== '') {
                    throw new \Appwrite\Extend\Exception(
                        \Appwrite\Extend\Exception::PAYMENT_PROVIDER_ALREADY_CONFIGURED,
                        "Provider '{$providerId}' is already configured. Disconnect it first before reconfiguring."
                    );
                }
            }
        }

        // Identify newly added and removed providers
        $newProviders = [];
        $removedProviders = [];

        foreach ($providers as $providerId => $providerConfig) {
            if (!isset($existingProviders[$providerId])) {
                $newProviders[] = $providerId;
            }
        }

        foreach ($existingProviders as $providerId => $providerConfig) {
            if (!isset($providers[$providerId])) {
                $removedProviders[] = $providerId;
            }
        }

        foreach ($providers as $providerId => $providerConfig) {
            $adapter = $registryPayments->get($providerId, (array) $providerConfig, $project, $dbForPlatform, $dbForProject);
            $test = $adapter->testConnection((array) $providerConfig);
            if (!$test->success) {
                \error_log("[Payments/Update] provider={$providerId} test failed: {$test->message}");
                throw new \Appwrite\Extend\Exception(\Appwrite\Extend\Exception::GENERAL_BAD_REQUEST, 'Provider test failed: ' . $test->message);
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
        $mergedProviderKeys = \array_keys((array) ($merged['providers'] ?? []));
        $queueForEvents->setParam('providers', empty($mergedProviderKeys) ? 'providers' : \implode(',', $mergedProviderKeys));

        // Sync existing plans to newly added providers
        if (!empty($newProviders)) {
            $allPlans = $dbForProject->find('payments_plans', [
                Query::limit(1000)
            ]);

            foreach ($allPlans as $plan) {
                $planId = (string) $plan->getAttribute('planId', '');
                $planName = (string) $plan->getAttribute('name', '');
                $planDescription = (string) $plan->getAttribute('description', '');
                $pricing = (array) $plan->getAttribute('pricing', []);
                $providersMeta = (array) $plan->getAttribute('providers', []);

                foreach ($newProviders as $providerId) {
                    if (isset($providersMeta[$providerId])) {
                        continue; // Skip if plan already exists for this provider
                    }

                    $providerConfig = (array) ($providers[$providerId] ?? []);
                    $state = new ProviderState((string) $providerId, (array) $providerConfig, (array) ($providerConfig['state'] ?? []));
                    $adapter = $registryPayments->get((string) $providerId, (array) $providerConfig, $project, $dbForPlatform, $dbForProject);

                    try {
                        $ref = $adapter->ensurePlan([
                            'planId' => $planId,
                            'name' => $planName,
                            'description' => $planDescription,
                            'pricing' => $pricing,
                        ], $state);

                        $meta = $ref->metadata;
                        $providersMeta[$providerId] = [
                            'externalId' => $ref->externalPlanId,
                            'metadata' => $meta,
                            'prices' => (array) ($meta['prices'] ?? [])
                        ];
                    } catch (\Throwable $e) {
                        \error_log("[Payments/Update] Failed to sync plan {$planId} to provider {$providerId}: {$e->getMessage()}");
                        // Continue with other plans even if one fails
                    }
                }

                if (!empty($providersMeta)) {
                    $plan->setAttribute('providers', $providersMeta);
                    $dbForProject->updateDocument('payments_plans', $plan->getId(), $plan);
                }
            }
        }

        // Remove plans from de-configured providers
        if (!empty($removedProviders)) {
            $allPlans = $dbForProject->find('payments_plans', [
                Query::limit(1000)
            ]);

            foreach ($allPlans as $plan) {
                $providersMeta = (array) $plan->getAttribute('providers', []);
                $planUpdated = false;

                foreach ($removedProviders as $providerId) {
                    if (!isset($providersMeta[$providerId])) {
                        continue; // Skip if plan doesn't exist for this provider
                    }

                    $providerConfig = (array) ($existingProviders[$providerId] ?? []);
                    $state = new ProviderState((string) $providerId, (array) $providerConfig, (array) ($providerConfig['state'] ?? []));
                    $adapter = $registryPayments->get((string) $providerId, (array) $providerConfig, $project, $dbForPlatform, $dbForProject);

                    $providerMeta = (array) $providersMeta[$providerId];
                    $ref = new ProviderPlanRef(
                        (string) ($providerMeta['externalId'] ?? ''),
                        (array) ($providerMeta['metadata'] ?? [])
                    );

                    try {
                        $adapter->deletePlan($ref, $state);
                    } catch (\Throwable $e) {
                        \error_log("[Payments/Update] Failed to delete plan from provider {$providerId}: {$e->getMessage()}");
                        // Continue with deletion from metadata even if provider deletion fails
                    }

                    // Remove provider entry from plan metadata
                    unset($providersMeta[$providerId]);
                    $planUpdated = true;
                }

                if ($planUpdated) {
                    $plan->setAttribute('providers', $providersMeta);
                    $dbForProject->updateDocument('payments_plans', $plan->getId(), $plan);
                }
            }
        }

        $out = (array) $updated->getAttribute('payments', []);
        $prov = (array) ($out['providers'] ?? []);
        foreach ($prov as &$cfg) {
            if (isset($cfg['secretKey'])) {
                $cfg['secretKey'] = '***';
            }
            if (isset($cfg['webhookSecret'])) {
                $cfg['webhookSecret'] = '***';
            }
        }
        $out['providers'] = $prov;
        $response->dynamic(new Document($out), Response::MODEL_PAYMENT_PROVIDER_CONFIG);
    }
}
