<?php

namespace Appwrite\Platform\Modules\Payments\Http\Subscriptions;

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
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Update extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'updatePaymentSubscription';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/payments/subscriptions/:subscriptionId')
            ->groups(['api', 'payments'])
            ->desc('Update subscription')
            ->label('scope', 'payments.subscribe')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('event', 'payments.subscriptions.update')
            ->label('audits.event', 'payments.subscription.update')
            ->label('audits.resource', 'payments/subscription/{request.subscriptionId}')
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'subscriptions',
                name: 'update',
                description: 'Update a subscription',
                auth: [AuthType::KEY, AuthType::ADMIN, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ANY,
                    )
                ]
            ))
            ->param('subscriptionId', '', new Text(128), 'Subscription ID')
            ->param('planId', '', new Text(128), 'New plan ID', true)
            ->param('priceId', '', new Text(128), 'New price ID', true)
            ->param('cancelAtPeriodEnd', false, new Boolean(), 'Cancel at period end', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('registryPayments')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $subscriptionId,
        string $planId,
        string $priceId,
        bool $cancelAtPeriodEnd,
        Response $response,
        Database $dbForPlatform,
        Database $dbForProject,
        Document $user,
        Registry $registryPayments,
        Document $project
    ) {
        // Feature flag: block if payments disabled for project
        $projDoc = $dbForPlatform->getDocument('projects', $project->getId());
        $paymentsCfg = (array) $projDoc->getAttribute('payments', []);
        if (isset($paymentsCfg['enabled']) && $paymentsCfg['enabled'] === false) {
            $response->setStatusCode(Response::STATUS_CODE_FORBIDDEN);
            $response->json(['message' => 'Payments feature is disabled for this project']);
            return;
        }

        $sub = $dbForPlatform->findOne('payments_subscriptions', [
            Query::equal('projectId', [$project->getId()]),
            Query::equal('subscriptionId', [$subscriptionId])
        ]);
        if ($sub === null || $sub->isEmpty()) {
            $response->setStatusCode(Response::STATUS_CODE_NOT_FOUND);
            $response->json(['message' => 'Subscription not found']);
            return;
        }

        // Authorization: if acting as user (JWT), enforce actor ownership or team membership roles
        if (!$user->isEmpty()) {
            $actorType = (string) $sub->getAttribute('actorType', 'user');
            $actorId = (string) $sub->getAttribute('actorId', '');
            if ($actorType === 'user') {
                if ($user->getId() !== $actorId) {
                    $response->setStatusCode(Response::STATUS_CODE_FORBIDDEN);
                    $response->json(['message' => 'Not allowed to modify this subscription']);
                    return;
                }
            } elseif ($actorType === 'team') {
                $membership = $dbForProject->findOne('memberships', [
                    Query::equal('teamId', [$actorId]),
                    Query::equal('userId', [$user->getId()])
                ]);
                if ($membership === null || $membership->isEmpty()) {
                    $response->setStatusCode(Response::STATUS_CODE_FORBIDDEN);
                    $response->json(['message' => 'Not a member of the team']);
                    return;
                }
                $roles = (array) $membership->getAttribute('roles', []);
                if (!in_array('owner', $roles, true) && !in_array('billing', $roles, true)) {
                    $response->setStatusCode(Response::STATUS_CODE_FORBIDDEN);
                    $response->json(['message' => 'Requires owner or billing role']);
                    return;
                }
            }
        }
        // Provider update
        $payments = (array) $project->getAttribute('payments', []);
        $providers = (array) ($payments['providers'] ?? []);
        $primary = array_key_first($providers);
        if ($primary) {
            $config = (array) ($providers[$primary] ?? []);
            $provMap = (array) $sub->getAttribute('providers', []);
            $subscriptionRef = (string) ((array) ($provMap[(string) $primary] ?? []))['subscriptionId'] ?? '';
            if ($subscriptionRef !== '') {
                $state = new ProviderState((string) $primary, $config, (array) ($config['state'] ?? []));
                $adapter = $registryPayments->get((string) $primary, $config, $project, $dbForPlatform, $dbForProject);
                if ($planId !== '' || $priceId !== '') {
                    $currentPlanId = (string) $sub->getAttribute('planId', '');
                    $targetPlanId = $planId !== '' ? $planId : $currentPlanId;
                    $plan = $dbForPlatform->findOne('payments_plans', [
                        Query::equal('projectId', [$project->getId()]),
                        Query::equal('planId', [$targetPlanId])
                    ]);
                    if ($plan === null || $plan->isEmpty()) {
                        $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
                        $response->json(['message' => 'Target plan not found']);
                        return;
                    }
                    $planProviders = (array) $plan->getAttribute('providers', []);
                    $planPricing = array_values((array) ($plan->getAttribute('pricing') ?? []));
                    $providerEntry = (array) ($planProviders[(string) $primary] ?? []);
                    $rawProviderPrices = (array) ($providerEntry['prices'] ?? []);
                    $providerPriceMap = [];
                    if (!empty($rawProviderPrices)) {
                        if (!\array_is_list($rawProviderPrices)) {
                            foreach ($rawProviderPrices as $internalId => $providerPrice) {
                                $internalKey = (string) $internalId;
                                $providerValue = (string) $providerPrice;
                                if ($internalKey !== '' && $providerValue !== '') {
                                    $providerPriceMap[$internalKey] = $providerValue;
                                }
                            }
                        } else {
                            foreach ($planPricing as $index => $pricingEntry) {
                                $internalId = (string) ($pricingEntry['priceId'] ?? '');
                                $providerValue = (string) ($rawProviderPrices[$index] ?? '');
                                if ($internalId !== '' && $providerValue !== '') {
                                    $providerPriceMap[$internalId] = $providerValue;
                                }
                            }
                        }
                    }
                    if (empty($providerPriceMap)) {
                        $metaPrices = (array) (($providerEntry['metadata']['prices'] ?? []) ?: []);
                        foreach ($metaPrices as $internalId => $providerPrice) {
                            $internalKey = (string) $internalId;
                            $providerValue = (string) $providerPrice;
                            if ($internalKey !== '' && $providerValue !== '') {
                                $providerPriceMap[$internalKey] = $providerValue;
                            }
                        }
                    }

                    $selectedPriceId = $priceId !== '' ? $priceId : (string) $sub->getAttribute('priceId', '');
                    if ($selectedPriceId === '' && !empty($providerPriceMap)) {
                        $selectedPriceId = (string) array_key_first($providerPriceMap);
                    }

                    if ($selectedPriceId === '' || !isset($providerPriceMap[$selectedPriceId])) {
                        $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
                        $response->json(['message' => 'Price ID not configured for provider']);
                        return;
                    }

                    $providerPriceId = (string) $providerPriceMap[$selectedPriceId];
                    $adapter->updateSubscription(
                        new \Appwrite\Payments\Provider\ProviderSubscriptionRef($subscriptionRef),
                        ['priceId' => $providerPriceId],
                        $state
                    );
                    $sub->setAttribute('planId', $targetPlanId);
                    $sub->setAttribute('priceId', $selectedPriceId);
                    $provEntry = (array) ($provMap[(string) $primary] ?? []);
                    $provEntry['priceId'] = $selectedPriceId;
                    $provEntry['providerPriceId'] = $providerPriceId;
                    $provMap[(string) $primary] = $provEntry;
                    $sub->setAttribute('providers', $provMap);
                }
                if ($cancelAtPeriodEnd) {
                    $adapter->cancelSubscription(new \Appwrite\Payments\Provider\ProviderSubscriptionRef($subscriptionRef), true, $state);
                    $sub->setAttribute('cancelAtPeriodEnd', true);
                }
            }
        }
        $sub = $dbForPlatform->updateDocument('payments_subscriptions', $sub->getId(), $sub);
        $response->json($sub->getArrayCopy());
    }
}
