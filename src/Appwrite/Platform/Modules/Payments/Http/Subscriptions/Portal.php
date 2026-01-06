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
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Portal extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createPaymentSubscriptionPortal';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/payments/subscriptions/:subscriptionId/portal')
            ->groups(['api', 'payments'])
            ->desc('Create billing portal session')
            ->label('scope', 'payments.subscribe')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'subscriptions',
                name: 'createPortal',
                description: 'Create a billing portal session',
                auth: [AuthType::KEY, AuthType::ADMIN, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ANY,
                    )
                ]
            ))
            ->param('subscriptionId', '', new Text(128), 'Subscription ID')
            ->param('returnUrl', '', new Text(2048), 'Return URL after portal session', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('registryPayments')
            ->inject('project')
            ->inject('user')
            ->callback($this->action(...));
    }

    public function action(
        string $subscriptionId,
        string $returnUrl,
        Response $response,
        Database $dbForPlatform,
        Database $dbForProject,
        Registry $registryPayments,
        Document $project,
        Document $user
    ) {
        // Feature flag: block if payments disabled
        $projDoc = $dbForPlatform->getDocument('projects', $project->getId());
        $paymentsCfg = (array) $projDoc->getAttribute('payments', []);
        if (isset($paymentsCfg['enabled']) && $paymentsCfg['enabled'] === false) {
            $response->setStatusCode(Response::STATUS_CODE_FORBIDDEN);
            $response->json(['message' => 'Payments feature is disabled for this project']);
            return;
        }

        // Get subscription from database
        $subscription = $dbForProject->findOne('payments_subscriptions', [
            Query::equal('subscriptionId', [$subscriptionId])
        ]);

        if ($subscription === null || $subscription->isEmpty()) {
            $response->setStatusCode(Response::STATUS_CODE_NOT_FOUND);
            $response->json(['message' => 'Subscription not found']);
            return;
        }

        // Authorization: only enforce for JWT users, API keys have admin access
        if (!$user->isEmpty()) {
            $actorType = (string) $subscription->getAttribute('actorType', '');
            $actorId = (string) $subscription->getAttribute('actorId', '');

            if ($actorType === 'user') {
                if ($user->getId() !== $actorId) {
                    throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::USER_UNAUTHORIZED, 'Not authorized to access this subscription');
                }
            } elseif ($actorType === 'team') {
                $membership = $dbForProject->findOne('memberships', [
                    Query::equal('teamId', [$actorId]),
                    Query::equal('userId', [$user->getId()])
                ]);

                if ($membership === null || $membership->isEmpty()) {
                    throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::USER_UNAUTHORIZED, 'User is not a member of this team');
                }

                $roles = (array) $membership->getAttribute('roles', []);
                if (!in_array('owner', $roles, true) && !in_array('billing', $roles, true)) {
                    throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::USER_UNAUTHORIZED, 'User must have owner or billing role');
                }
            }
        }

        $actorType = (string) $subscription->getAttribute('actorType', '');
        $actorId = (string) $subscription->getAttribute('actorId', '');

        // Get actor document
        $actor = $actorType === 'user'
            ? $dbForProject->getDocument('users', $actorId)
            : $dbForProject->getDocument('teams', $actorId);

        if ($actor->isEmpty()) {
            $response->setStatusCode(Response::STATUS_CODE_NOT_FOUND);
            $response->json(['message' => 'Actor not found']);
            return;
        }

        // Get payment provider and create portal session
        $payments = (array) $project->getAttribute('payments', []);
        $providers = (array) ($payments['providers'] ?? []);
        $primary = array_key_first($providers);

        if (!$primary) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
            $response->json(['message' => 'No payment provider configured for this project']);
            return;
        }

        $config = (array) ($providers[$primary] ?? []);
        $adapter = $registryPayments->get((string) $primary, $config, $project, $dbForPlatform, $dbForProject);
        $state = new \Appwrite\Payments\Provider\ProviderState((string) $primary, $config, (array) ($config['state'] ?? []));

        if (!$adapter instanceof StripeAdapter) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
            $response->json(['message' => 'Unsupported payment provider: ' . $primary]);
            return;
        }

        try {
            $portalSession = $adapter->createPortalSession($actor, $state, [
                'returnUrl' => $returnUrl
            ]);
        } catch (\Throwable $e) {
            $response->setStatusCode(Response::STATUS_CODE_INTERNAL_SERVER_ERROR);
            $response->json(['message' => 'Failed to create portal session: ' . $e->getMessage()]);
            return;
        }

        $response->setStatusCode(Response::STATUS_CODE_OK);
        $response->json([
            'url' => $portalSession->url
        ]);
    }
}
