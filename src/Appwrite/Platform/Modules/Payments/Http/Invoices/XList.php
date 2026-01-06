<?php

namespace Appwrite\Platform\Modules\Payments\Http\Invoices;

use Appwrite\Payments\Provider\Registry;
use Appwrite\Payments\Provider\StripeAdapter;
use Appwrite\Payments\Provider\ProviderSubscriptionRef;
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
use Utopia\Validator\Integer;
use Utopia\Validator\Text;

class XList extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listPaymentInvoices';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/payments/subscriptions/:subscriptionId/invoices')
            ->groups(['api', 'payments'])
            ->desc('List subscription invoices')
            ->label('scope', 'payments.read')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'subscriptions',
                name: 'listInvoices',
                description: 'List invoices for a subscription',
                auth: [AuthType::KEY, AuthType::ADMIN, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ANY,
                    )
                ]
            ))
            ->param('subscriptionId', '', new Text(128), 'Subscription ID')
            ->param('limit', 25, new Integer(true), 'Maximum number of invoices to return (max 100)', true)
            ->param('offset', 0, new Integer(true), 'Offset for pagination', true)
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
        int $limit,
        int $offset,
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

        // Validate limit
        if ($limit < 1 || $limit > 100) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
            $response->json(['message' => 'Limit must be between 1 and 100']);
            return;
        }

        // Validate offset
        if ($offset < 0) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
            $response->json(['message' => 'Offset must be non-negative']);
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

        // Get provider subscription ID
        $providerSubscriptionId = (string) $subscription->getAttribute('providerSubscriptionId', '');

        if ($providerSubscriptionId === '') {
            // No provider subscription yet, return empty list
            $response->setStatusCode(Response::STATUS_CODE_OK);
            $response->json([
                'total' => 0,
                'invoices' => []
            ]);
            return;
        }

        // Get payment provider
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

        // Create provider subscription reference
        $providerSubRef = new ProviderSubscriptionRef(
            externalSubscriptionId: $providerSubscriptionId
        );

        try {
            $invoices = $adapter->listInvoices($providerSubRef, $state, $limit, $offset);
        } catch (\Throwable $e) {
            $response->setStatusCode(Response::STATUS_CODE_INTERNAL_SERVER_ERROR);
            $response->json(['message' => 'Failed to fetch invoices: ' . $e->getMessage()]);
            return;
        }

        // Format invoices for response
        $formattedInvoices = [];
        foreach ($invoices as $invoice) {
            $formattedInvoices[] = [
                'invoiceId' => $invoice->invoiceId,
                'subscriptionId' => $invoice->subscriptionId,
                'amount' => $invoice->amount,
                'currency' => $invoice->currency,
                'status' => $invoice->status,
                'createdAt' => $invoice->createdAt,
                'paidAt' => $invoice->paidAt,
                'invoiceUrl' => $invoice->invoiceUrl,
                'metadata' => $invoice->metadata,
            ];
        }

        $response->setStatusCode(Response::STATUS_CODE_OK);
        $response->json([
            'total' => count($formattedInvoices),
            'invoices' => $formattedInvoices
        ]);
    }
}
