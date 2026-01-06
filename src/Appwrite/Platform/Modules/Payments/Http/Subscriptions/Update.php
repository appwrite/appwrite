<?php

namespace Appwrite\Platform\Modules\Payments\Http\Subscriptions;

use Appwrite\AppwriteException;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception as ExtendException;
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
            ->label('event', 'payments.subscription.[subscriptionId].update')
            ->label('audits.event', 'payments.subscription.update')
            ->label('audits.resource', 'payments/subscription/{request.subscriptionId}')
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'subscriptions',
                name: 'update',
                description: <<<EOT
                Update a subscription to change the associated plan. This may trigger prorated charges or credits depending on the provider configuration.
                EOT,
                auth: [AuthType::KEY, AuthType::ADMIN, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PAYMENT_SUBSCRIPTION,
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
            ->inject('queueForEvents')
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
        Document $project,
        Event $queueForEvents
    ) {
        // Feature flag: block if payments disabled for project
        $projDoc = $dbForPlatform->getDocument('projects', $project->getId());
        $paymentsCfg = (array) $projDoc->getAttribute('payments', []);
        if (isset($paymentsCfg['enabled']) && $paymentsCfg['enabled'] === false) {
            throw new AppwriteException(ExtendException::GENERAL_ACCESS_FORBIDDEN, 'Payments feature is disabled for this project');
        }

        $sub = $dbForProject->findOne('payments_subscriptions', [
            Query::equal('subscriptionId', [$subscriptionId])
        ]);
        if ($sub === null || $sub->isEmpty()) {
            throw new AppwriteException(ExtendException::PAYMENT_SUBSCRIPTION_NOT_FOUND);
        }

        // Authorization: if acting as user (JWT), enforce actor ownership or team membership roles
        if (!$user->isEmpty()) {
            $actorType = (string) $sub->getAttribute('actorType', 'user');
            $actorId = (string) $sub->getAttribute('actorId', '');
            if ($actorType === 'user') {
                if ($user->getId() !== $actorId) {
                    throw new AppwriteException(ExtendException::USER_UNAUTHORIZED, 'Not allowed to modify this subscription');
                }
            } elseif ($actorType === 'team') {
                $membership = $dbForProject->findOne('memberships', [
                    Query::equal('teamId', [$actorId]),
                    Query::equal('userId', [$user->getId()])
                ]);
                if ($membership === null || $membership->isEmpty()) {
                    throw new AppwriteException(ExtendException::USER_UNAUTHORIZED, 'Not a member of the team');
                }
                $roles = (array) $membership->getAttribute('roles', []);
                if (!in_array('owner', $roles, true) && !in_array('billing', $roles, true)) {
                    throw new AppwriteException(ExtendException::USER_UNAUTHORIZED, 'Requires owner or billing role');
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
            $providerData = (array) ($provMap[(string) $primary] ?? []);
            $subscriptionRef = (string) ($providerData['providerSubscriptionId'] ?? '');
            if ($subscriptionRef !== '') {
                $state = new ProviderState((string) $primary, $config, (array) ($config['state'] ?? []));
                $adapter = $registryPayments->get((string) $primary, $config, $project, $dbForPlatform, $dbForProject);
                if ($planId !== '' || $priceId !== '') {
                    $currentPlanId = (string) $sub->getAttribute('planId', '');
                    $targetPlanId = $planId !== '' ? $planId : $currentPlanId;
                    $plan = $dbForProject->findOne('payments_plans', [
                        Query::equal('planId', [$targetPlanId])
                    ]);
                    if ($plan === null || $plan->isEmpty()) {
                        throw new AppwriteException(ExtendException::PAYMENT_PLAN_NOT_FOUND);
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
                        throw new AppwriteException(ExtendException::GENERAL_BAD_REQUEST, 'Price ID not configured for provider');
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
                } elseif (!$cancelAtPeriodEnd && $sub->getAttribute('cancelAtPeriodEnd', false) === true) {
                    // Resume if explicitly setting to false while subscription is scheduled to cancel
                    $adapter->resumeSubscription(new \Appwrite\Payments\Provider\ProviderSubscriptionRef($subscriptionRef), $state);
                    $sub->setAttribute('cancelAtPeriodEnd', false);
                }
            }
        }
        $sub = $dbForProject->updateDocument('payments_subscriptions', $sub->getId(), $sub);

        $queueForEvents
            ->setParam('subscriptionId', $subscriptionId)
            ->setPayload($sub->getArrayCopy());

        $response->dynamic($sub, Response::MODEL_PAYMENT_SUBSCRIPTION);
    }
}
