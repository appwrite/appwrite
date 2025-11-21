<?php

namespace Appwrite\Platform\Modules\Payments\Http\Subscriptions;

use Appwrite\Auth\Auth;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Get extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getPaymentSubscription';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/payments/subscriptions/:actorType/:actorId')
            ->httpAlias('/v1/payments/subscriptions')
            ->httpAlias('/v1/payments/subscriptions/current')
            ->httpAlias('/v1/payments/subscriptions/me')
            ->groups(['api', 'payments'])
            ->desc('Get subscription')
            ->label('scope', 'payments.read')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'subscriptions',
                name: 'get',
                description: 'Get a subscription',
                auth: [AuthType::KEY, AuthType::ADMIN, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PAYMENT_SUBSCRIPTION,
                    )
                ]
            ))
            ->param('actorType', 'user', new Text(16), 'Actor type: user or team', true)
            ->param('actorId', '', new Text(128), 'Actor ID', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->callback($this->action(...));
    }

    public function action(
        string $actorType,
        string $actorId,
        Response $response,
        Database $dbForProject,
        Document $user
    ) {
        // Handle case where path parameters might not be extracted (e.g., from aliases)
        if ($actorType === '' || $actorType === ':actorType') {
            $actorType = 'user';
        }
        if ($actorId === '' || $actorId === ':actorId') {
            $actorId = '';
        }

        $actorType = strtolower($actorType);

        if (!\in_array($actorType, ['user', 'team'], true)) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
            $response->json(['message' => 'actorType must be "user" or "team"']);
            return;
        }

        $roles = Authorization::getRoles();
        $isAPIKey = Auth::isAppUser($roles);
        $isPrivileged = Auth::isPrivilegedUser($roles);

        if ($actorType === 'user') {
            if ($actorId === '' || $actorId === 'current' || $actorId === 'me') {
                if ($user->isEmpty()) {
                    if ($isAPIKey || $isPrivileged) {
                        $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
                        $response->json(['message' => 'actorId is required when using API keys or privileged access']);
                    } else {
                        $response->setStatusCode(Response::STATUS_CODE_UNAUTHORIZED);
                        $response->json(['message' => 'Login required']);
                    }
                    return;
                }
                $actorId = $user->getId();
            } elseif (!$isAPIKey && !$isPrivileged) {
                if ($user->isEmpty() || $user->getId() !== $actorId) {
                    $response->setStatusCode(Response::STATUS_CODE_UNAUTHORIZED);
                    $response->json(['message' => 'Not allowed to access this subscription']);
                    return;
                }
            }
        }

        if ($actorType === 'team') {
            if ($actorId === '') {
                $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
                $response->json(['message' => 'actorId required for team subscriptions']);
                return;
            }

            if (!$isAPIKey && !$isPrivileged) {
                if ($user->isEmpty()) {
                    $response->setStatusCode(Response::STATUS_CODE_UNAUTHORIZED);
                    $response->json(['message' => 'Login required']);
                    return;
                }

                $membership = $dbForProject->findOne('memberships', [
                    Query::equal('teamId', [$actorId]),
                    Query::equal('userId', [$user->getId()])
                ]);

                if ($membership === null || $membership->isEmpty()) {
                    $response->setStatusCode(Response::STATUS_CODE_UNAUTHORIZED);
                    $response->json(['message' => 'User is not a member of this team']);
                    return;
                }
            }
        }

        if ($actorId !== '') {
            $collection = $actorType === 'team' ? 'teams' : 'users';
            $actor = $dbForProject->getDocument($collection, $actorId);

            if ($actor->isEmpty()) {
                $response->setStatusCode(Response::STATUS_CODE_NOT_FOUND);
                $response->json(['message' => 'Actor not found']);
                return;
            }
        }

        $queries = [
            Query::equal('actorType', [$actorType]),
            Query::equal('actorId', [$actorId]),
            Query::notEqual('status', 'pending'),
            Query::orderDesc('$createdAt'),
            Query::limit(1),
        ];

        $subscriptions = $dbForProject->find('payments_subscriptions', $queries);
        $subscription = $subscriptions[0] ?? null;

        $activeSubscription = null;
        if ($subscription instanceof Document && !$subscription->isEmpty()) {
            $status = strtolower((string) $subscription->getAttribute('status', ''));
            if ($status == 'active' || $status == 'trialing' || $status == 'paused') {
                $activeSubscription = $subscription;
            }
        }

        $planId = '';
        if ($activeSubscription instanceof Document) {
            $planId = (string) $activeSubscription->getAttribute('planId', '');
        }
        if ($planId === '') {
            $planId = 'free';
        }

        $planData = null;
        $features = [];

        $plan = $dbForProject->findOne('payments_plans', [
            Query::equal('planId', [$planId])
        ]);
        if ($plan && !$plan->isEmpty()) {
            $planData = $plan->getArrayCopy();

            $planFeatures = $dbForProject->find('payments_plan_features', [
                Query::equal('planId', [$planId]),
                Query::equal('enabled', [true]),
            ]);

            foreach ($planFeatures as $featureDoc) {
                if (!$featureDoc instanceof Document) {
                    continue;
                }
                $featureId = (string) $featureDoc->getAttribute('featureId', '');
                $featureDetails = $dbForProject->findOne('payments_features', [
                    Query::equal('featureId', [$featureId])
                ]);

                $features[] = [
                    'featureId' => $featureId,
                    'name' => $featureDetails?->getAttribute('name'),
                    'description' => $featureDetails?->getAttribute('description'),
                    'type' => $featureDoc->getAttribute('type'),
                    'includedUnits' => $featureDoc->getAttribute('includedUnits'),
                    'usageCap' => $featureDoc->getAttribute('usageCap'),
                    'tiersMode' => $featureDoc->getAttribute('tiersMode'),
                    'tiers' => $featureDoc->getAttribute('tiers'),
                    'currency' => $featureDoc->getAttribute('currency'),
                    'interval' => $featureDoc->getAttribute('interval'),
                ];
            }
        }

        $featuresSanitized = [];
        foreach ($features as $feature) {
            $sanitized = json_decode(json_encode($feature), false);
            $featuresSanitized[] = $sanitized ?? new \stdClass();
        }

        $subscriptionDoc = $activeSubscription instanceof Document ? $activeSubscription : new Document([]);

        // Convert planData to plain array/object
        $planDataObj = null;
        if ($planData !== null) {
            if ($planData instanceof Document) {
                $planDataObj = json_decode(json_encode($planData->getArrayCopy()), false);
            } elseif (is_array($planData)) {
                $planDataObj = json_decode(json_encode($planData), false);
            } else {
                $planDataObj = $planData;
            }
        }
        if ($planDataObj === null) {
            $planDataObj = new \stdClass();
        }

        // Convert subscription data to plain array/object
        $subscriptionData = new \stdClass();
        if (!$subscriptionDoc->isEmpty()) {
            $subscriptionData = (object) [
                'subscriptionId' => (string) $subscriptionDoc->getAttribute('subscriptionId', ''),
                'status' => (string) $subscriptionDoc->getAttribute('status', ''),
                'priceId' => (string) $subscriptionDoc->getAttribute('priceId', ''),
                'trialEndsAt' => $subscriptionDoc->getAttribute('trialEndsAt'),
                'currentPeriodStart' => $subscriptionDoc->getAttribute('currentPeriodStart'),
                'currentPeriodEnd' => $subscriptionDoc->getAttribute('currentPeriodEnd'),
                'cancelAtPeriodEnd' => (bool) $subscriptionDoc->getAttribute('cancelAtPeriodEnd', false),
            ];
        }

        $providersRaw = $subscriptionDoc->getAttribute('providers', []) ?: new \stdClass();
        if ($providersRaw instanceof Document) {
            $providersRaw = $providersRaw->getArrayCopy();
        }
        $providersSanitized = json_decode(json_encode($providersRaw), false);
        if ($providersSanitized === null) {
            $providersSanitized = new \stdClass();
        }

        $payload = new Document([
            'subscriptionId' => (string) $subscriptionDoc->getAttribute('subscriptionId', ''),
            'actorType' => $actorType,
            'actorId' => $actorId,
            'planId' => $planId,
            'priceId' => (string) $subscriptionDoc->getAttribute('priceId', ''),
            'status' => (string) $subscriptionDoc->getAttribute('status', ''),
            'providers' => $providersSanitized,
            'plan' => $planDataObj,
            'features' => $featuresSanitized,
            'subscription' => $subscriptionData,
        ]);

        $response->dynamic($payload, Response::MODEL_PAYMENT_SUBSCRIPTION);
    }
}
