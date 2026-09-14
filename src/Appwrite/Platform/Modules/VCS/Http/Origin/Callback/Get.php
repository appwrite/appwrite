<?php

namespace Appwrite\Platform\Modules\VCS\Http\Origin\Callback;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Permission as AppwritePermission;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
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
        return 'getVCSOriginCallback';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vcs/origin/callback')
            ->desc('Get installation from Origin app')
            ->groups(['api', 'vcs'])
            ->label('scope', 'public')
            ->label('error', APP_VIEWS_DIR . '/general/error.phtml')
            ->param('installation_id', '', new Text(256, 0), 'Origin installation ID', true)
            ->param('installation_receipt', '', new Text(8192, 0), 'Origin installation receipt JWT, signed by Cursor.', true)
            ->param('state', '', new Text(4096, 0), 'Origin state. Contains info sent when starting the installation flow.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('platform')
            ->inject('vcsFactory')
            ->callback($this->action(...));
    }

    public function action(
        string $providerInstallationId,
        string $installationReceipt,
        string $state,
        Response $response,
        Database $dbForPlatform,
        array $platform,
        VcsFactory $vcsFactory,
    ) {
        if (empty($installationReceipt)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Missing installation receipt. Please restart the installation from the Appwrite Console.');
        }

        // Authenticate the callback before trusting anything else on it: the
        // endpoint is public and Origin performs no token exchange, so the
        // EdDSA-signed receipt is the only proof the request came from Cursor.
        /** @var \Utopia\VCS\Adapter\Git\Origin $vcs */
        $vcs = $vcsFactory->fromProvider('origin');

        try {
            $receiptClaims = $vcs->verifyReceipt($installationReceipt, System::getEnv('_APP_VCS_ORIGIN_CLIENT_ID', ''));
        } catch (\Throwable $e) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Could not verify the Origin installation receipt. Please restart the installation from the Appwrite Console.');
        }

        // The receipt's subject is the authoritative installation id; a
        // separately passed installation_id may only confirm it.
        $receiptInstallationId = \strval($receiptClaims['sub'] ?? '');
        if (!empty($providerInstallationId) && $providerInstallationId !== $receiptInstallationId) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Receipt subject does not match the installation ID.');
        }
        $providerInstallationId = $receiptInstallationId;

        // Cursor echoes the state given to the install URL as a receipt claim;
        // accept a query parameter carrying the same value as a fallback.
        $state = !empty($state) ? $state : \strval($receiptClaims['state'] ?? '');
        if (empty($state)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Missing state parameter. Please restart the installation from the Appwrite Console.');
        }

        $state = \json_decode($state, true) ?? [];
        $projectId = $state['projectId'] ?? '';
        $redirectFailure = $state['failure'] ?? '';

        // The receipt authenticates Cursor, and this HMAC authenticates the
        // Appwrite Console that started the flow -- without it anyone could
        // attach an installation to an arbitrary projectId.
        $signature = \hash_hmac('sha256', \json_encode([$projectId, $state['success'] ?? '', $redirectFailure]), System::getEnv('_APP_OPENSSL_KEY_V1', ''));
        if (!\hash_equals($signature, $state['signature'] ?? '')) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid state parameter. Please restart the installation from the Appwrite Console.');
        }

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            $this->failure($response, $redirectFailure, 'Project with the ID from state could not be found.', Exception::PROJECT_NOT_FOUND);
            return;
        }

        $region = $project->getAttribute('region', 'default');
        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $hostname = $platform['consoleHostname'] ?? '';

        $defaultState = [
            'success' => $protocol . '://' . $hostname . "/console/project-$region-$projectId/settings/git-installations",
            'failure' => $protocol . '://' . $hostname . "/console/project-$region-$projectId/settings/git-installations",
        ];

        $state = \array_merge($defaultState, \array_filter($state));
        $redirectSuccess = $state['success'] ?? '';
        $redirectFailure = $state['failure'] ?? '';

        if (empty($providerInstallationId)) {
            $this->failure($response, $redirectFailure, 'Origin installation ID is missing.');
            return;
        }

        // The receipt names the workspace by its stable id; the owner slug the
        // rest of the integration works with comes from the installation itself.
        $organization = \strval($receiptClaims['namespace_id'] ?? '');
        try {
            $adapter = $vcsFactory->fromInstallation(new Document([
                'provider' => 'origin',
                'providerInstallationId' => $providerInstallationId,
            ]));
            $organization = $adapter->getOwnerName($providerInstallationId);
        } catch (\Throwable $e) {
            Console::warning('Failed to resolve Origin installation owner: ' . $e->getMessage());
        }

        $projectInternalId = $project->getSequence();

        $installation = $dbForPlatform->findOne('installations', [
            Query::equal('providerInstallationId', [$providerInstallationId]),
            Query::equal('projectInternalId', [$projectInternalId]),
            Query::equal('provider', ['origin']),
        ]);

        if ($installation->isEmpty()) {
            $teamId = $project->getAttribute('teamId', '');

            $installation = $dbForPlatform->createDocument('installations', new Document([
                '$id' => ID::unique(),
                '$permissions' => $this->getPermissions($teamId, $projectId),
                'providerInstallationId' => $providerInstallationId,
                'projectId' => $projectId,
                'projectInternalId' => $projectInternalId,
                'provider' => 'origin',
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
