<?php

namespace Appwrite\Platform\Modules\Payments\Http\Subscriptions;

use Appwrite\Event\Event;
use Appwrite\Payments\Provider\Registry;
use Appwrite\Payments\Provider\StripeAdapter;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createPaymentSubscription';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/payments/subscriptions')
            ->groups(['api', 'payments'])
            ->desc('Create subscription')
            ->label('scope', 'payments.subscribe')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('event', 'payments.subscription.[subscriptionId].create')
            ->label('audits.event', 'payments.subscription.create')
            ->label('audits.resource', 'payments/subscription/{response.subscriptionId}')
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'subscriptions',
                name: 'createSubscription',
                description: <<<EOT
                Create a new subscription for a user or team. This initiates a checkout session with the configured payment provider and returns a checkout URL for payment completion.
                EOT,
                auth: [AuthType::KEY, AuthType::ADMIN, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_PAYMENT_SUBSCRIPTION,
                    )
                ]
            ))
            ->param('actorType', 'user', new Text(16), 'Actor type: user or team')
            ->param('actorId', '', new Text(128), 'Actor ID')
            ->param('planId', '', new Text(128), 'Plan ID')
            ->param('priceId', '', new Text(128), 'Price ID', true)
            ->param('payerUserId', '', new Text(128, 0), 'Payer user ID (for team subscriptions)', true)
            ->param('successUrl', '', new Text(2048), 'Success redirect URL')
            ->param('cancelUrl', '', new Text(2048), 'Cancel redirect URL')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('registryPayments')
            ->inject('project')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $actorType,
        string $actorId,
        string $planId,
        string $priceId,
        string $payerUserId,
        string $successUrl,
        string $cancelUrl,
        Response $response,
        Database $dbForPlatform,
        Database $dbForProject,
        Registry $registryPayments,
        Document $project,
        Event $queueForEvents
    ) {
        // Feature flag: block if payments disabled
        $projDoc = $dbForPlatform->getDocument('projects', $project->getId());
        $paymentsCfg = (array) $projDoc->getAttribute('payments', []);
        if (isset($paymentsCfg['enabled']) && $paymentsCfg['enabled'] === false) {
            throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::GENERAL_ACCESS_FORBIDDEN, 'Payments feature is disabled for this project');
        }

        $plan = $dbForProject->findOne('payments_plans', [
            Query::equal('planId', [$planId])
        ]);
        if ($plan === null || $plan->isEmpty()) {
            throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::PAYMENT_PLAN_NOT_FOUND);
        }

        // Resolve payer (user who owns payment method). For teams, use payerUserId; else actorId
        if ($actorType === 'team' && $payerUserId === '') {
            throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::GENERAL_BAD_REQUEST, 'payerUserId required for team subscriptions');
        }
        $payerId = $actorType === 'team' ? $payerUserId : $actorId;
        $payer = $dbForProject->getDocument('users', $payerId);
        if ($payer->isEmpty()) {
            throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::USER_NOT_FOUND, 'Payer user not found');
        }
        if ($actorType === 'team') {
            // Ensure payer is a member with billing/owner role
            $membership = $dbForProject->findOne('memberships', [
                Query::equal('teamId', [$actorId]),
                Query::equal('userId', [$payerId])
            ]);
            if ($membership === null || $membership->isEmpty()) {
                throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::USER_UNAUTHORIZED, 'Payer is not a member of the team');
            }
            $roles = (array) $membership->getAttribute('roles', []);
            if (!in_array('owner', $roles, true) && !in_array('billing', $roles, true)) {
                throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::USER_UNAUTHORIZED, 'Payer must have owner or billing role');
            }
        }

        // Fetch actor document to get internal ID
        $actor = $actorType === 'user'
            ? $dbForProject->getDocument('users', $actorId)
            : $dbForProject->getDocument('teams', $actorId);

        if ($actor->isEmpty()) {
            $exceptionType = $actorType === 'user' ? \Appwrite\Extend\Exception::USER_NOT_FOUND : \Appwrite\Extend\Exception::TEAM_NOT_FOUND;
            throw new \Appwrite\AppwriteException($exceptionType);
        }

        // Check if actor already has an active subscription
        $existingSubscriptions = $dbForProject->find('payments_subscriptions', [
            Query::equal('actorType', [$actorType]),
            Query::equal('actorId', [$actorId]),
            Query::equal('status', ['active', 'trialing', 'paused']),
            Query::limit(1),
        ]);

        if (!empty($existingSubscriptions)) {
            $existingSubscription = $existingSubscriptions[0];
            if ($existingSubscription instanceof Document && !$existingSubscription->isEmpty()) {
                throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::PAYMENT_SUBSCRIPTION_ALREADY_EXISTS, 'Actor already has an active subscription');
            }
        }

        // Get payment provider and create checkout session
        $payments = (array) $project->getAttribute('payments', []);
        $providers = (array) ($payments['providers'] ?? []);
        $primary = array_key_first($providers);
        $initialStatus = 'pending';
        $providerData = [];
        $providerSubscriptionId = null;
        $providerCheckoutId = null;
        $checkoutUrl = null;
        $selectedPriceId = $priceId !== '' ? $priceId : null;
        $providerPlanPriceId = '';

        if ($primary) {
            $config = (array) ($providers[$primary] ?? []);
            $planProviders = (array) $plan->getAttribute('providers', []);
            $adapter = $registryPayments->get((string) $primary, $config, $project, $dbForPlatform, $dbForProject);
            $state = new \Appwrite\Payments\Provider\ProviderState((string) $primary, $config, (array) ($config['state'] ?? []));

            $planPricing = array_values((array) ($plan->getAttribute('pricing') ?? []));
            $providerEntry = (array) ($planProviders[$primary] ?? []);
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

            if ($selectedPriceId !== null) {
                if (!isset($providerPriceMap[$selectedPriceId])) {
                    throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::GENERAL_BAD_REQUEST, 'Price ID not configured for provider: ' . $selectedPriceId);
                }
                $providerPlanPriceId = (string) $providerPriceMap[$selectedPriceId];
            } elseif (!empty($providerPriceMap)) {
                $selectedPriceId = (string) array_key_first($providerPriceMap);
                $providerPlanPriceId = (string) $providerPriceMap[$selectedPriceId];
            }

            if ($providerPlanPriceId === '') {
                // Legacy fallback: use first available provider price ID even if internal mapping missing
                $legacyList = (array) ($providerEntry['prices'] ?? []);
                if (\array_is_list($legacyList)) {
                    foreach ($legacyList as $legacyPriceId) {
                        if (!empty($legacyPriceId)) {
                            $providerPlanPriceId = (string) $legacyPriceId;
                            break;
                        }
                    }
                }
            }

            if ($providerPlanPriceId === '') {
                throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::GENERAL_BAD_REQUEST, 'Plan has no prices configured for provider: ' . $primary);
            }

            $providerKey = (string) $primary;
            $providerEntryData = [];
            $providerSubscriptionId = null;
            $providerCheckoutId = null;

            if (!$adapter instanceof StripeAdapter) {
                throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::GENERAL_BAD_REQUEST, 'Unsupported payment provider: ' . $providerKey);
            }

            if ($successUrl === '' || $cancelUrl === '') {
                throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::GENERAL_BAD_REQUEST, 'successUrl and cancelUrl are required.');
            }

            // Fetch metered feature prices for this plan
            $meteredPriceIds = [];
            $planFeatures = $dbForProject->find('payments_plan_features', [
                Query::equal('planId', [$planId]),
                Query::equal('enabled', [true]),
            ]);
            foreach ($planFeatures as $planFeature) {
                $featureType = (string) $planFeature->getAttribute('type', '');
                if ($featureType !== 'metered') {
                    continue;
                }
                $featureProviders = (array) $planFeature->getAttribute('providers', []);
                $featureProviderData = (array) ($featureProviders[$primary] ?? []);
                $featurePriceId = (string) ($featureProviderData['priceId'] ?? '');
                if ($featurePriceId !== '') {
                    $meteredPriceIds[] = $featurePriceId;
                }
            }

            try {
                $checkoutSession = $adapter->createCheckoutSession($payer, [
                    'priceId' => $providerPlanPriceId,
                    'meteredPriceIds' => $meteredPriceIds,
                ], $state, [
                    'successUrl' => $successUrl,
                    'cancelUrl' => $cancelUrl
                ]);
            } catch (\Throwable $e) {
                throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::GENERAL_SERVER_ERROR, 'Failed to create checkout session: ' . $e->getMessage());
            }

            $checkoutUrl = $checkoutSession->url;
            $providerCheckoutId = (string) ($checkoutSession->metadata['id'] ?? '');
            if ($providerCheckoutId === '') {
                throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::GENERAL_SERVER_ERROR, 'Checkout session did not return an id');
            }
            $providerCustomerId = (string) ($checkoutSession->metadata['customerId'] ?? '');
            $providerEntryData = [
                'priceId' => (string) ($selectedPriceId ?? ''),
                'providerPriceId' => (string) $providerPlanPriceId,
                'providerCheckoutId' => $providerCheckoutId,
            ];
            if ($providerCustomerId !== '') {
                $providerEntryData['providerCustomerId'] = $providerCustomerId;
            }
            $initialStatus = 'pending';

            $providerData[$providerKey] = $providerEntryData;
        } else {
            throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::PAYMENT_PROVIDER_NOT_CONFIGURED);
        }

        $resolvedPriceId = (string) ($selectedPriceId ?? '');

        $subscription = new Document([
            'subscriptionId' => ID::unique(),
            'providerSubscriptionId' => $providerSubscriptionId,
            'providerCheckoutId' => $providerCheckoutId,
            'actorType' => $actorType,
            'actorId' => $actorId,
            'actorInternalId' => $actor->getSequence(),
            'planId' => $planId,
            'priceId' => $resolvedPriceId,
            'status' => $initialStatus,
            'trialEndsAt' => null,
            'currentPeriodStart' => null,
            'currentPeriodEnd' => null,
            'cancelAtPeriodEnd' => false,
            'canceledAt' => null,
            'providers' => $providerData,
            'usageSummary' => [],
            'tags' => [],
            'search' => implode(' ', [$actorType, $actorId, $planId])
        ]);

        $created = $dbForProject->createDocument('payments_subscriptions', $subscription);

        $queueForEvents
            ->setProject($project)
            ->setParam('subscriptionId', $created->getAttribute('subscriptionId'))
            ->setEvent('payments.subscription.[subscriptionId].create')
            ->setPayload($created->getArrayCopy());

        // Add checkoutUrl to the response document
        if ($checkoutUrl) {
            $created->setAttribute('checkoutUrl', $checkoutUrl);
        }

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($created, Response::MODEL_PAYMENT_SUBSCRIPTION);
    }
}
