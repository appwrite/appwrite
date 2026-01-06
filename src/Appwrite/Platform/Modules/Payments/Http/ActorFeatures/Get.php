<?php

namespace Appwrite\Platform\Modules\Payments\Http\ActorFeatures;

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
        return 'getActorFeatures';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/payments/actors/:actorType/:actorId/features')
            ->httpAlias('/v1/payments/actors/features')
            ->httpAlias('/v1/payments/actors/current/features')
            ->httpAlias('/v1/payments/actors/me/features')
            ->groups(['api', 'payments'])
            ->desc('Get features for an actor')
            ->label('scope', 'payments.read')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'actorFeatures',
                name: 'getActorFeatures',
                description: <<<EOT
                Get the features available to a specific actor (user or team) based on their active subscription.
                EOT,
                auth: [AuthType::KEY, AuthType::ADMIN, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ANY,
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

        // Authorization: Handle user actor type
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
                // Non-privileged users can only access their own features
                if ($user->isEmpty() || $user->getId() !== $actorId) {
                    throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::USER_UNAUTHORIZED, 'Not allowed to access this actor\'s features');
                }
            }
        }

        // Authorization: Handle team actor type
        if ($actorType === 'team') {
            if ($actorId === '') {
                throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::GENERAL_BAD_REQUEST, 'actorId required for team features');
            }

            if (!$isAPIKey && !$isPrivileged) {
                if ($user->isEmpty()) {
                    throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::USER_UNAUTHORIZED, 'Login required');
                }

                // Verify user is a member of the team
                $membership = $dbForProject->findOne('memberships', [
                    Query::equal('teamId', [$actorId]),
                    Query::equal('userId', [$user->getId()])
                ]);

                if ($membership === null || $membership->isEmpty()) {
                    throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::USER_UNAUTHORIZED, 'User is not a member of this team');
                }
            }
        }

        // Verify the actor exists
        if ($actorId !== '') {
            $collection = $actorType === 'team' ? 'teams' : 'users';
            $actor = $dbForProject->getDocument($collection, $actorId);

            if ($actor->isEmpty()) {
                $exceptionType = $actorType === 'user' ? \Appwrite\Extend\Exception::USER_NOT_FOUND : \Appwrite\Extend\Exception::TEAM_NOT_FOUND;
                throw new \Appwrite\AppwriteException($exceptionType);
            }
        }

        // Get the actor's subscription
        $queries = [
            Query::equal('actorType', [$actorType]),
            Query::equal('actorId', [$actorId]),
            Query::notEqual('status', 'pending'),
            Query::orderDesc('$createdAt'),
            Query::limit(1),
        ];

        $subscriptions = $dbForProject->find('payments_subscriptions', $queries);
        $subscription = $subscriptions[0] ?? null;

        // Determine active subscription
        $activeSubscription = null;
        if ($subscription instanceof Document && !$subscription->isEmpty()) {
            $status = strtolower((string) $subscription->getAttribute('status', ''));
            if ($status == 'active' || $status == 'trialing' || $status == 'paused') {
                $activeSubscription = $subscription;
            }
        }

        // Get the plan ID (default to 'free' if no active subscription)
        $planId = '';
        if ($activeSubscription instanceof Document) {
            $planId = (string) $activeSubscription->getAttribute('planId', '');
        }
        if ($planId === '') {
            $planId = 'free';
        }

        // Fetch plan features
        $features = [];
        $planFeatures = $dbForProject->find('payments_plan_features', [
            Query::equal('planId', [$planId]),
            Query::equal('enabled', [true]),
        ]);

        foreach ($planFeatures as $planFeatureDoc) {
            if (!$planFeatureDoc instanceof Document) {
                continue;
            }

            $featureId = (string) $planFeatureDoc->getAttribute('featureId', '');

            // Get feature details from payments_features collection
            $featureDetails = $dbForProject->findOne('payments_features', [
                Query::equal('featureId', [$featureId])
            ]);

            if (!$featureDetails || $featureDetails->isEmpty()) {
                continue;
            }

            // Build comprehensive feature information
            $feature = [
                'featureId' => $featureId,
                'name' => $featureDetails->getAttribute('name', ''),
                'description' => $featureDetails->getAttribute('description', ''),
                'type' => $planFeatureDoc->getAttribute('type', 'boolean'),
                'enabled' => true,
            ];

            // Add metered feature details if applicable
            if ($planFeatureDoc->getAttribute('type') === 'metered') {
                $feature['includedUnits'] = $planFeatureDoc->getAttribute('includedUnits', 0);
                $feature['usageCap'] = $planFeatureDoc->getAttribute('usageCap', null);
                $feature['tiersMode'] = $planFeatureDoc->getAttribute('tiersMode', '');
                $feature['tiers'] = $planFeatureDoc->getAttribute('tiers', []);
                $feature['currency'] = $planFeatureDoc->getAttribute('currency', '');
                $feature['interval'] = $planFeatureDoc->getAttribute('interval', '');
            }

            $features[] = $feature;
        }

        // Sanitize features to ensure proper JSON encoding
        $featuresSanitized = [];
        foreach ($features as $feature) {
            $sanitized = json_decode(json_encode($feature), false);
            $featuresSanitized[] = $sanitized ?? new \stdClass();
        }

        $payload = [
            'actorType' => $actorType,
            'actorId' => $actorId,
            'planId' => $planId,
            'total' => count($featuresSanitized),
            'features' => $featuresSanitized,
        ];

        $response->json($payload);
    }
}
