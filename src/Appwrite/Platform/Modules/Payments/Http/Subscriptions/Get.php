<?php

namespace Appwrite\Platform\Modules\Payments\Http\Subscriptions;

use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
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
                name: 'getSubscription',
                description: <<<EOT
                Get a subscription by its unique ID.
                EOT,
                auth: [AuthType::KEY, AuthType::ADMIN, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PAYMENT_SUBSCRIPTION,
                    )
                ]
            ))
            ->param('actorType', 'user', new Text(16), 'Actor type: user or team')
            ->param('actorId', 'me', new Text(128), 'Actor ID. Use "me" or "current" for the logged-in user.')
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
            throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::GENERAL_BAD_REQUEST, 'actorType must be "user" or "team"');
        }

        $roles = Authorization::getRoles();
        $isAPIKey = User::isApp($roles);
        $isPrivileged = User::isPrivileged($roles);

        if ($actorType === 'user') {
            if ($actorId === '' || $actorId === 'current' || $actorId === 'me') {
                if ($user->isEmpty()) {
                    if ($isAPIKey || $isPrivileged) {
                        throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::GENERAL_BAD_REQUEST, 'actorId is required when using API keys or privileged access');
                    } else {
                        throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::USER_UNAUTHORIZED, 'Login required');
                    }
                }
                $actorId = $user->getId();
            } elseif (!$isAPIKey && !$isPrivileged) {
                if ($user->isEmpty() || $user->getId() !== $actorId) {
                    throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::USER_UNAUTHORIZED, 'Not allowed to access this subscription');
                }
            }
        }

        if ($actorType === 'team') {
            if ($actorId === '') {
                throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::GENERAL_BAD_REQUEST, 'actorId required for team subscriptions');
            }

            if (!$isAPIKey && !$isPrivileged) {
                if ($user->isEmpty()) {
                    throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::USER_UNAUTHORIZED, 'Login required');
                }

                $membership = $dbForProject->findOne('memberships', [
                    Query::equal('teamId', [$actorId]),
                    Query::equal('userId', [$user->getId()])
                ]);

                if ($membership === null || $membership->isEmpty()) {
                    throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::USER_UNAUTHORIZED, 'User is not a member of this team');
                }
            }
        }

        if ($actorId !== '') {
            $collection = $actorType === 'team' ? 'teams' : 'users';
            $actor = $dbForProject->getDocument($collection, $actorId);

            if ($actor->isEmpty()) {
                $exceptionType = $actorType === 'user' ? \Appwrite\Extend\Exception::USER_NOT_FOUND : \Appwrite\Extend\Exception::TEAM_NOT_FOUND;
                throw new \Appwrite\AppwriteException($exceptionType);
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

        $subscriptionDoc = $activeSubscription instanceof Document ? $activeSubscription : new Document([]);

        $response->dynamic($subscriptionDoc, Response::MODEL_PAYMENT_SUBSCRIPTION);
    }
}
