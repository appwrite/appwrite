<?php

namespace Appwrite\Platform\Modules\Payments\Http\Invoices;

use Appwrite\Extend\Exception;
use Appwrite\Payments\Provider\ProviderState;
use Appwrite\Payments\Provider\ProviderSubscriptionRef;
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
                description: <<<EOT
                Get a list of invoices for a subscription from the payment provider.
                EOT,
                auth: [AuthType::KEY, AuthType::ADMIN, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PAYMENT_INVOICE_LIST,
                    )
                ]
            ))
            ->param('subscriptionId', '', new Text(128), 'Subscription ID.')
            ->param('limit', 25, new Integer(true), 'Maximum number of invoices to return. Max 100.', true)
            ->param('offset', 0, new Integer(true), 'Offset for pagination.', true)
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
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, 'Payments feature is disabled for this project');
        }

        // Validate limit
        if ($limit < 1 || $limit > 100) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Limit must be between 1 and 100');
        }

        // Validate offset
        if ($offset < 0) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Offset must be non-negative');
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

        // Get provider from subscription
        $subscriptionProviders = (array) $subscription->getAttribute('providers', []);
        $providerId = array_key_first($subscriptionProviders);

        if (!$providerId) {
            // No provider on subscription, return empty list
            $response->dynamic(new Document([
                'invoices' => [],
                'total' => 0,
            ]), Response::MODEL_PAYMENT_INVOICE_LIST);
            return;
        }

        $providerData = (array) ($subscriptionProviders[$providerId] ?? []);
        $providerSubscriptionId = (string) ($providerData['providerSubscriptionId'] ?? '');

        if ($providerSubscriptionId === '') {
            // No provider subscription yet, return empty list
            $response->dynamic(new Document([
                'invoices' => [],
                'total' => 0,
            ]), Response::MODEL_PAYMENT_INVOICE_LIST);
            return;
        }

        // Get payment provider configuration
        $providers = (array) ($paymentsCfg['providers'] ?? []);
        if (!isset($providers[$providerId])) {
            throw new Exception(Exception::PAYMENT_PROVIDER_NOT_CONFIGURED, 'Payment provider not configured for this project');
        }

        $config = (array) ($providers[$providerId] ?? []);
        $adapter = $registryPayments->get((string) $providerId, $config, $projDoc, $dbForPlatform, $dbForProject);
        $state = new ProviderState((string) $providerId, $config, (array) ($config['state'] ?? []));

        // Create provider subscription reference
        $providerSubRef = new ProviderSubscriptionRef(
            externalSubscriptionId: $providerSubscriptionId
        );

        try {
            $invoices = $adapter->listInvoices($providerSubRef, $state, $limit, $offset);
        } catch (\Throwable $e) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to fetch invoices: ' . $e->getMessage());
        }

        // Format invoices for response
        $formattedInvoices = [];
        foreach ($invoices as $invoice) {
            $formattedInvoices[] = new Document([
                'invoiceId' => $invoice->invoiceId,
                'subscriptionId' => $invoice->subscriptionId,
                'amount' => $invoice->amount,
                'currency' => $invoice->currency,
                'status' => $invoice->status,
                'createdAt' => $invoice->createdAt,
                'paidAt' => $invoice->paidAt,
                'invoiceUrl' => $invoice->invoiceUrl,
                'metadata' => $invoice->metadata,
            ]);
        }

        $response->dynamic(new Document([
            'invoices' => $formattedInvoices,
            'total' => count($formattedInvoices),
        ]), Response::MODEL_PAYMENT_INVOICE_LIST);
    }
}
