<?php

namespace Appwrite\Platform\Modules\VCS\Http\Origin\Callback;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Permission as AppwritePermission;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\State;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Id;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
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
            ->param('state', '', new Text(APP_LIMIT_VCS_STATE, 0), 'Origin state. Contains info sent when starting the installation flow.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('user')
            ->inject('authorization')
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
        User $user,
        Authorization $authorization,
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

        // Cursor echoes the state given to the install URL as a receipt claim,
        // so the claim is the authoritative one; a query parameter may only
        // carry the same value, never a different one.
        $receiptState = \strval($receiptClaims['state'] ?? '');
        if (!empty($receiptState) && !empty($state) && !\hash_equals($receiptState, $state)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Receipt state does not match the state parameter.');
        }

        $state = !empty($receiptState) ? $receiptState : $state;
        if (empty($state)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Missing state parameter. Please restart the installation from the Appwrite Console.');
        }

        // The receipt authenticates Cursor, and this signature the Appwrite
        // Console session that started the flow -- without it anyone could
        // attach an installation to an arbitrary projectId.
        $signingKey = System::getEnv('_APP_OPENSSL_KEY_V1', '');
        if (empty($signingKey)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Signing key is not configured. Please configure _APP_OPENSSL_KEY_V1 in .env file.');
        }

        $state = State::verify($state, $signingKey);

        if ($state === null) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid state parameter. Please restart the installation from the Appwrite Console.');
        }

        $projectId = $state->projectId;
        $settings = ($platform['consoleUrl'] ?? '') . "/projects/$projectId/settings";
        $redirectSuccess = $state->success ?: $settings;
        $redirectFailure = $state->failure ?: $settings;

        if ($state->isExpired()) {
            $this->failure($response, $redirectFailure, 'The installation request has expired. Please restart the installation from the Appwrite Console.');
            return;
        }

        if ($user->isEmpty() || $user->getId() !== $state->userId) {
            $this->failure($response, $redirectFailure, 'The installation was started by a different Appwrite account. Please sign in with that account, or restart the installation from the Appwrite Console.');
            return;
        }

        // Each state completes one installation; ids are capped at 36 characters
        try {
            $authorization->skip(fn () => $dbForPlatform->createDocument('vcsCommentLocks', new Document([
                '$id' => 'vcs-state-' . \substr(\hash('sha256', $state->nonce), 0, 26),
            ])));
        } catch (Duplicate) {
            $this->failure($response, $redirectFailure, 'This installation request was already used. Please restart the installation from the Appwrite Console.');
            return;
        }

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            $this->failure($response, $redirectFailure, 'Project with the ID from state could not be found.', Exception::PROJECT_NOT_FOUND);
            return;
        }

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
                '$id' => Id::unique(),
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
