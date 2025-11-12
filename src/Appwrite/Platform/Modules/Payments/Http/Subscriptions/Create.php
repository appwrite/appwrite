<?php

namespace Appwrite\Platform\Modules\Payments\Http\Subscriptions;

use Appwrite\Event\Event;
use Appwrite\Payments\Provider\Registry;
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
                name: 'create',
                description: 'Create a subscription',
                auth: [AuthType::KEY, AuthType::ADMIN, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_ANY,
                    )
                ]
            ))
            ->param('actorType', 'user', new Text(16), 'Actor type: user or team')
            ->param('actorId', '', new Text(128), 'Actor ID')
            ->param('planId', '', new Text(128), 'Plan ID')
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
            $response->setStatusCode(Response::STATUS_CODE_FORBIDDEN);
            $response->json(['message' => 'Payments feature is disabled for this project']);
            return;
        }

        $plan = $dbForPlatform->findOne('payments_plans', [
            Query::equal('projectId', [$project->getId()]),
            Query::equal('planId', [$planId])
        ]);
        if ($plan === null || $plan->isEmpty()) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
            $response->json(['message' => 'Invalid planId']);
            return;
        }

        // Resolve payer (user who owns payment method). For teams, use payerUserId; else actorId
        if ($actorType === 'team' && $payerUserId === '') {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
            $response->json(['message' => 'payerUserId required for team subscriptions']);
            return;
        }
        $payerId = $actorType === 'team' ? $payerUserId : $actorId;
        $payer = $dbForProject->getDocument('users', $payerId);
        if ($payer->isEmpty()) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
            $response->json(['message' => 'Payer user not found']);
            return;
        }
        if ($actorType === 'team') {
            // Ensure payer is a member with billing/owner role
            $membership = $dbForProject->findOne('memberships', [
                Query::equal('teamId', [$actorId]),
                Query::equal('userId', [$payerId])
            ]);
            if ($membership === null || $membership->isEmpty()) {
                $response->setStatusCode(Response::STATUS_CODE_FORBIDDEN);
                $response->json(['message' => 'Payer is not a member of the team']);
                return;
            }
            $roles = (array) $membership->getAttribute('roles', []);
            if (!in_array('owner', $roles, true) && !in_array('billing', $roles, true)) {
                $response->setStatusCode(Response::STATUS_CODE_FORBIDDEN);
                $response->json(['message' => 'Payer must have owner or billing role']);
                return;
            }
        }

        // Fetch actor document to get internal ID
        $actor = $actorType === 'user'
            ? $dbForProject->getDocument('users', $actorId)
            : $dbForProject->getDocument('teams', $actorId);

        if ($actor->isEmpty()) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
            $response->json(['message' => 'Actor not found']);
            return;
        }

        // Get payment provider and create checkout session
        $payments = (array) $project->getAttribute('payments', []);
        $providers = (array) ($payments['providers'] ?? []);
        $primary = array_key_first($providers);
        $initialStatus = 'pending';
        $providerData = [];
        $checkoutUrl = null;

        if ($primary) {
            $config = (array) ($providers[$primary] ?? []);
            $planProviders = (array) $plan->getAttribute('providers', []);
            $adapter = $registryPayments->get((string) $primary, $config, $project, $dbForPlatform, $dbForProject);
            $state = new \Appwrite\Payments\Provider\ProviderState((string) $primary, $config, (array) ($config['state'] ?? []));

            // Find the fixed plan price (not metered features)
            // Plan prices are stored first in the prices array, followed by feature prices
            $priceIds = (array) ($planProviders[$primary]['prices'] ?? []);

            // If no prices in plan providers, return error
            if (empty($priceIds)) {
                $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
                $response->json(['message' => 'Plan has no prices configured for provider: ' . $primary]);
                return;
            }

            // Use the first price ID as the plan price
            // Plan prices are created first and stored first in the array (see StripeAdapter::ensurePlan)
            $planPriceId = null;
            foreach ($priceIds as $priceId) {
                if (!empty($priceId)) {
                    $planPriceId = $priceId;
                    break;
                }
            }

            if ($planPriceId === null) {
                $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
                $response->json(['message' => 'Plan has no valid prices configured for provider: ' . $primary]);
                return;
            }

            // Create checkout session if we have a price ID and URLs
            if ($planPriceId && $successUrl !== '' && $cancelUrl !== '') {
                try {
                    $checkoutSession = $adapter->createCheckoutSession($payer, [
                        'priceId' => $planPriceId
                    ], $state, [
                        'successUrl' => $successUrl,
                        'cancelUrl' => $cancelUrl
                    ]);
                    $checkoutUrl = $checkoutSession->url;
                } catch (\Throwable $e) {
                    // Log error but continue with subscription creation
                    // The subscription can be created without checkout URL for manual payment flows
                }
            }

            // Create or ensure subscription exists in provider
            try {
                $subRef = $adapter->ensureSubscription($payer, [
                    'planId' => $planId,
                    'planProviders' => $planProviders
                ], $state);
                $providerData = [ (string) $primary => [ 'subscriptionId' => $subRef->externalSubscriptionId ] ];
                // Use status from provider if available
                $initialStatus = (string) ($subRef->metadata['status'] ?? 'pending');
            } catch (\Throwable $e) {
                $response->setStatusCode(Response::STATUS_CODE_INTERNAL_SERVER_ERROR);
                $response->json(['message' => 'Failed to create subscription: ' . $e->getMessage()]);
                return;
            }
        } else {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
            $response->json(['message' => 'No payment provider configured for this project']);
            return;
        }

        $subscription = new Document([
            'subscriptionId' => ID::unique(),
            'projectId' => $project->getId(),
            'projectInternalId' => $project->getSequence(),
            'actorType' => $actorType,
            'actorId' => $actorId,
            'actorInternalId' => $actor->getSequence(),
            'planId' => $planId,
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

        $created = $dbForPlatform->createDocument('payments_subscriptions', $subscription);

        $queueForEvents
            ->setProject($project)
            ->setEvent('payments.subscription.[subscriptionId].create')
            ->setParam('subscriptionId', $created->getAttribute('subscriptionId'))
            ->setPayload($created->getArrayCopy());

        $responseData = $created->getArrayCopy();
        if ($checkoutUrl) {
            $responseData['checkoutUrl'] = $checkoutUrl;
        }

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->json($responseData);
    }
}
