<?php

namespace Appwrite\Platform\Modules\VCS\Http\Entire\Callback;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Permission as AppwritePermission;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Text;

class Get extends Action
{
    use HTTP;
    use AppwritePermission;

    public static function getName()
    {
        return 'getVCSEntireCallback';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vcs/entire/callback')
            ->desc('Get installation from Entire plugin')
            ->groups(['api', 'vcs'])
            ->label('scope', 'public')
            ->label('error', APP_VIEWS_DIR . '/general/error.phtml')
            ->param('installation_id', '', new Text(256, 0), 'Entire installation ID', true)
            ->param('receipt', '', new Text(8192, 0), 'Entire installation receipt JWT, signed by the Entire core.', true)
            ->param('state', '', new Text(APP_LIMIT_VCS_STATE, 0), 'Entire state. Contains info sent when starting the installation flow.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('platform')
            ->inject('vcsFactory')
            ->callback($this->action(...));
    }

    public function action(
        string $providerInstallationId,
        string $receipt,
        string $state,
        Response $response,
        Database $dbForPlatform,
        array $platform,
        VcsFactory $vcsFactory,
    ) {
        if (empty($receipt)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Missing installation receipt. Please restart the installation from the Appwrite Console.');
        }

        // Authenticate the callback before trusting anything else on it: the
        // endpoint is public and Entire performs no token exchange, so the
        // EdDSA-signed receipt is the only proof the request came from Entire.
        /** @var \Utopia\VCS\Adapter\Git\Entire $vcs */
        $vcs = $vcsFactory->fromProvider('entire');

        try {
            $receiptClaims = $vcs->verifyReceipt($receipt, System::getEnv('_APP_VCS_ENTIRE_PLUGIN', ''));
        } catch (\Throwable $e) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Could not verify the Entire installation receipt. Please restart the installation from the Appwrite Console.');
        }

        // The receipt's subject is the authoritative installation id; a
        // separately passed installation_id may only confirm it.
        $receiptInstallationId = \strval($receiptClaims['sub'] ?? '');
        if (!empty($providerInstallationId) && $providerInstallationId !== $receiptInstallationId) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Receipt subject does not match the installation ID.');
        }
        $providerInstallationId = $receiptInstallationId;

        // Entire echoes the state given to the install URL as a receipt claim;
        // accept a query parameter carrying the same value as a fallback.
        $state = !empty($state) ? $state : \strval($receiptClaims['state'] ?? '');
        if (empty($state)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Missing state parameter. Please restart the installation from the Appwrite Console.');
        }

        $state = \json_decode($state, true) ?? [];
        $projectId = $state['projectId'] ?? '';
        $redirectFailure = $state['failure'] ?? '';

        // The receipt authenticates Entire, and this HMAC authenticates the
        // Appwrite Console that started the flow -- without it anyone could
        // attach an installation to an arbitrary projectId.
        $signature = \hash_hmac('sha256', \json_encode([$projectId, $state['success'] ?? '', $redirectFailure]), System::getEnv('_APP_OPENSSL_KEY_V1', ''));
        if (!\hash_equals($signature, \is_string($state['signature'] ?? null) ? $state['signature'] : '')) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid state parameter. Please restart the installation from the Appwrite Console.');
        }

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            $this->failure($response, $redirectFailure, 'Project with the ID from state could not be found.', Exception::PROJECT_NOT_FOUND);
            return;
        }

        $defaultState = [
            'success' => ($platform['consoleUrl'] ?? '') . "/projects/$projectId/settings",
            'failure' => ($platform['consoleUrl'] ?? '') . "/projects/$projectId/settings",
        ];

        $state = \array_merge($defaultState, \array_filter($state));
        $redirectSuccess = $state['success'] ?? '';
        $redirectFailure = $state['failure'] ?? '';

        if (empty($providerInstallationId)) {
            $this->failure($response, $redirectFailure, 'Entire installation ID is missing.');
            return;
        }

        // An installation belongs to one Entire project, which is the owner
        // the rest of the integration works with. The receipt names it; the
        // repositories the installation covers confirm it.
        $organization = \strval($receiptClaims['project'] ?? '');
        try {
            $adapter = $vcsFactory->fromInstallation(new Document([
                'provider' => 'entire',
                'providerInstallationId' => $providerInstallationId,
            ]));
            $organization = $adapter->getOwnerName($providerInstallationId) ?: $organization;
        } catch (\Throwable $e) {
            Console::warning('Failed to resolve Entire installation owner: ' . $e->getMessage());
        }

        $projectInternalId = $project->getSequence();

        $installation = $dbForPlatform->findOne('installations', [
            Query::equal('providerInstallationId', [$providerInstallationId]),
            Query::equal('projectInternalId', [$projectInternalId]),
            Query::equal('provider', ['entire']),
        ]);

        if ($installation->isEmpty()) {
            $teamId = $project->getAttribute('teamId', '');

            $installation = $dbForPlatform->createDocument('installations', new Document([
                '$id' => Id::unique(),
                '$permissions' => $this->getPermissions($teamId, $projectId),
                'providerInstallationId' => $providerInstallationId,
                'projectId' => $projectId,
                'projectInternalId' => $projectInternalId,
                'provider' => 'entire',
                'organization' => $organization,
                'personal' => false,
            ]));
        } else {
            $installation = $dbForPlatform->updateDocument('installations', $installation->getId(), new Document([
                'organization' => $organization,
            ]));
        }

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($redirectSuccess);
    }

    /**
     * Redirect back to the console with an error, or throw when no redirect is available.
     */
    private function failure(Response $response, string $redirect, string $error, string $type = Exception::GENERAL_ARGUMENT_INVALID): void
    {
        if (empty($redirect)) {
            throw new Exception($type, $error);
        }

        $separator = \str_contains($redirect, '?') ? '&' : '?';
        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($redirect . $separator . \http_build_query(['error' => $error]));
    }
}
