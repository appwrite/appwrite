<?php

namespace Appwrite\Platform\Modules\Payments\Http\Subscriptions;

use Appwrite\Extend\Exception;
use Appwrite\Payments\Provider\ProviderSubscriptionRef;
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

class PreviewUpgrade extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'previewPaymentSubscriptionUpgrade';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/payments/subscriptions/:subscriptionId/preview')
            ->groups(['api', 'payments'])
            ->desc('Preview subscription upgrade proration')
            ->label('scope', 'payments.read')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'subscriptions',
                name: 'previewUpgrade',
                description: <<<EOT
                Preview the cost of upgrading or downgrading a subscription to a different plan.
                EOT,
                auth: [AuthType::KEY, AuthType::ADMIN, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ANY,
                    )
                ]
            ))
            ->param('subscriptionId', '', new Text(128), 'Subscription ID')
            ->param('newPlanId', '', new Text(128), 'New plan ID to switch to')
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
        string $newPlanId,
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
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, 'Payments feature is disabled for this project');
        }

        // Get subscription from database
        $subscription = $dbForProject->findOne('payments_subscriptions', [
            Query::equal('subscriptionId', [$subscriptionId])
        ]);

        if ($subscription === null || $subscription->isEmpty()) {
            throw new Exception(Exception::PAYMENT_SUBSCRIPTION_NOT_FOUND);
        }

        // Authorization: only enforce for JWT users, API keys have admin access
        if (!$user->isEmpty()) {
            $actorType = (string) $subscription->getAttribute('actorType', '');
            $actorId = (string) $subscription->getAttribute('actorId', '');

            if ($actorType === 'user') {
                if ($user->getId() !== $actorId) {
                    throw new Exception(Exception::USER_UNAUTHORIZED, 'Not authorized to access this subscription');
                }
            } elseif ($actorType === 'team') {
                $membership = $dbForProject->findOne('memberships', [
                    Query::equal('teamId', [$actorId]),
                    Query::equal('userId', [$user->getId()])
                ]);

                if ($membership === null || $membership->isEmpty()) {
                    throw new Exception(Exception::USER_UNAUTHORIZED, 'User is not a member of this team');
                }

                $roles = (array) $membership->getAttribute('roles', []);
                if (!in_array('owner', $roles, true) && !in_array('billing', $roles, true)) {
                    throw new Exception(Exception::USER_UNAUTHORIZED, 'User must have owner or billing role');
                }
            }
        }

        // Get provider subscription ID from providers map
        $payments = (array) $project->getAttribute('payments', []);
        $providers = (array) ($payments['providers'] ?? []);
        $primary = array_key_first($providers);

        if (!$primary) {
            throw new Exception(Exception::PAYMENT_PROVIDER_NOT_CONFIGURED, 'No payment provider configured');
        }

        $subProviders = (array) $subscription->getAttribute('providers', []);
        $subProviderData = (array) ($subProviders[$primary] ?? []);
        $providerSubscriptionId = (string) ($subProviderData['providerSubscriptionId'] ?? '');

        if ($providerSubscriptionId === '') {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Subscription has no provider subscription ID');
        }

        $config = (array) ($providers[$primary] ?? []);
        $adapter = $registryPayments->get((string) $primary, $config, $project, $dbForPlatform, $dbForProject);
        $state = new \Appwrite\Payments\Provider\ProviderState((string) $primary, $config, (array) ($config['state'] ?? []));

        if (!$adapter instanceof StripeAdapter) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Unsupported payment provider: ' . $primary);
        }

        // Get the NEW plan to find the provider price
        $newPlan = $dbForProject->findOne('payments_plans', [
            Query::equal('planId', [$newPlanId])
        ]);

        if ($newPlan === null || $newPlan->isEmpty()) {
            throw new Exception(Exception::PAYMENT_PLAN_NOT_FOUND);
        }

        // Get provider price from the new plan
        $planProviders = (array) $newPlan->getAttribute('providers', []);
        $providerEntry = (array) ($planProviders[$primary] ?? []);
        $providerPrices = (array) ($providerEntry['prices'] ?? []);

        // Try metadata prices as fallback
        if (empty($providerPrices)) {
            $providerPrices = (array) (($providerEntry['metadata']['prices'] ?? []) ?: []);
        }

        if (empty($providerPrices)) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'New plan has no provider prices configured');
        }

        // Get the first available provider price
        $providerPriceId = (string) reset($providerPrices);

        if ($providerPriceId === '') {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Could not determine provider price for new plan');
        }

        // Create provider subscription reference
        $providerSubRef = new ProviderSubscriptionRef(
            externalSubscriptionId: $providerSubscriptionId
        );

        try {
            $preview = $adapter->previewProration($providerSubRef, $providerPriceId, $state);
        } catch (\Throwable $e) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to preview proration: ' . $e->getMessage());
        }

        $response->setStatusCode(Response::STATUS_CODE_OK);
        $response->json([
            'planId' => $newPlanId,
            'amountDue' => $preview->amountDue,
            'prorationAmount' => $preview->prorationAmount,
            'currency' => $preview->currency,
            'nextBillingDate' => $preview->nextBillingDate,
            'metadata' => $preview->metadata,
        ]);
    }
}
