<?php

namespace Appwrite\Platform\Modules\VCS\Http\Entire\Callback;

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
            ->param('receipt', '', new Text(8192, 0), 'Entire installation receipt JWT, signed by the Entire core.', true)
            ->param('state', '', new Text(APP_LIMIT_VCS_STATE, 0), 'Entire state. Contains info sent when starting the installation flow.', true)
            ->param('error', '', new Text(256, 0), 'Entire error code, when the installation was not approved.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('user')
            ->inject('authorization')
            ->inject('platform')
            ->inject('vcsFactory')
            ->callback($this->action(...));
    }

    public function action(
        string $receipt,
        string $state,
        string $error,
        Response $response,
        Database $dbForPlatform,
        User $user,
        Authorization $authorization,
        array $platform,
        VcsFactory $vcsFactory,
    ) {
        $signingKey = System::getEnv('_APP_OPENSSL_KEY_V1', '');
        if (empty($signingKey)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Signing key is not configured. Please configure _APP_OPENSSL_KEY_V1 in .env file.');
        }

        // A customer who cancels on Entire comes back with an error and the
        // state instead of a receipt. Nothing else authenticates that request,
        // so the state must carry this server's signature before its failure
        // URL is trusted as a redirect. Nothing is granted on this path, so it
        // asks for no more than that signature.
        if (!empty($error) && empty($receipt)) {
            $cancelled = $this->verifiedState($state, $signingKey);
            $this->failure($response, $cancelled->failure, $error === 'access_denied' ? 'The installation was not approved on Entire.' : 'Entire refused the installation: ' . $error);
            return;
        }

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

        $providerInstallationId = \strval($receiptClaims['installation_id'] ?? '');

        // Entire echoes the state given to the install URL as a receipt claim,
        // so the claim is the authoritative one; a query parameter may only
        // carry the same value, never a different one. The receipt
        // authenticates Entire, and the state the Appwrite Console session
        // that started the flow -- without it anyone could attach an
        // installation to an arbitrary projectId.
        $receiptState = \strval($receiptClaims['state'] ?? '');
        if (!empty($receiptState) && !empty($state) && !\hash_equals($receiptState, $state)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Receipt state does not match the state parameter.');
        }

        $state = $this->verifiedState(!empty($receiptState) ? $receiptState : $state, $signingKey);

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

        // Receipts are single-use too: one replayed within its five-minute
        // life must not attach the installation again
        $receiptId = \strval($receiptClaims['jti'] ?? '');
        if (!empty($receiptId)) {
            try {
                $authorization->skip(fn () => $dbForPlatform->createDocument('vcsCommentLocks', new Document([
                    // Document ids are capped at 36 characters; a digest prefix keeps the claim within it
                    '$id' => 'entire-receipt-' . \substr(\hash('sha256', $receiptId), 0, 21),
                ])));
            } catch (Duplicate) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'The installation receipt was already used. Please restart the installation from the Appwrite Console.');
            }
        }

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            $this->failure($response, $redirectFailure, 'Project with the ID from state could not be found.', Exception::PROJECT_NOT_FOUND);
            return;
        }

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
     * The state the Authorize action signed. Throws when it is missing, or was
     * not signed by this server with the current key.
     */
    private function verifiedState(string $state, string $signingKey): State
    {
        if (empty($state)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Missing state parameter. Please restart the installation from the Appwrite Console.');
        }

        $verified = State::verify($state, $signingKey);

        if ($verified === null) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid state parameter. Please restart the installation from the Appwrite Console.');
        }

        return $verified;
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
