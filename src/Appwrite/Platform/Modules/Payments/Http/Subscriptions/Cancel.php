<?php

namespace Appwrite\Platform\Modules\Payments\Http\Subscriptions;

use Appwrite\Payments\Provider\ProviderState;
use Appwrite\Payments\Provider\Registry;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Cancel extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'cancelPaymentSubscription';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/payments/subscriptions/:subscriptionId/cancel')
            ->groups(['api', 'payments'])
            ->desc('Cancel subscription')
            ->label('scope', 'payments.subscribe')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('event', 'payments.subscriptions.cancel')
            ->label('audits.event', 'payments.subscription.cancel')
            ->label('audits.resource', 'payments/subscription/{request.subscriptionId}')
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'subscriptions',
                name: 'cancel',
                description: 'Cancel a subscription',
                auth: [AuthType::KEY, AuthType::ADMIN, AuthType::JWT],
                responses: []
            ))
            ->param('subscriptionId', '', new Text(128), 'Subscription ID')
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

        $sub = $dbForProject->findOne('payments_subscriptions', [
            Query::equal('subscriptionId', [$subscriptionId])
        ]);
        if ($sub === null || $sub->isEmpty()) {
            $response->setStatusCode(Response::STATUS_CODE_NOT_FOUND);
            $response->json(['message' => 'Subscription not found']);
            return;
        }

        // Authorization for JWT user: must be owner/billing on team or owner (self) on user
        if (!$user->isEmpty()) {
            $actorType = (string) $sub->getAttribute('actorType', 'user');
            $actorId = (string) $sub->getAttribute('actorId', '');
            if ($actorType === 'user') {
                if ($user->getId() !== $actorId) {
                    $response->setStatusCode(Response::STATUS_CODE_FORBIDDEN);
                    $response->json(['message' => 'Not allowed to cancel this subscription']);
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
        // Provider cancel
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
                $adapter->cancelSubscription(new \Appwrite\Payments\Provider\ProviderSubscriptionRef($subscriptionRef), true, $state);
            }
        }
        $sub->setAttribute('status', 'canceled');
        $sub->setAttribute('canceledAt', date('c'));
        $dbForProject->updateDocument('payments_subscriptions', $sub->getId(), $sub);
        $response->noContent();
    }
}
