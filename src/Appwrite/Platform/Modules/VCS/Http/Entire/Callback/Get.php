<?php

namespace Appwrite\Platform\Modules\VCS\Http\Entire\Callback;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Permission as AppwritePermission;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
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
        Authorization $authorization,
        array $platform,
        VcsFactory $vcsFactory,
    ) {
        // A customer who cancels on Entire comes back with an error and the
        // state instead of a receipt. Nothing else authenticates that request,
        // so the state must carry this server's signature before its failure
        // URL is trusted as a redirect.
        if (!empty($error) && empty($receipt)) {
            $cancelled = $this->verifiedState($state);
            $this->failure($response, \strval($cancelled['failure'] ?? ''), $error === 'access_denied' ? 'The installation was not approved on Entire.' : 'Entire refused the installation: ' . $error);
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

        // Entire echoes the state given to the install URL as a receipt claim;
        // accept a query parameter carrying the same value as a fallback. The
        // receipt authenticates Entire, and the state's signature the Appwrite
        // Console that started the flow -- without it anyone could attach an
        // installation to an arbitrary projectId.
        $state = $this->verifiedState(!empty($state) ? $state : \strval($receiptClaims['state'] ?? ''));
        $projectId = $state['projectId'] ?? '';
        $redirectFailure = $state['failure'] ?? '';

        // Receipts are single-use: one replayed within its five-minute life
        // must not attach the installation again
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
     * The state the Authorize action signed, decoded. Throws when it is
     * missing, or was not signed by this server with the current key.
     *
     * @return array<string, mixed>
     */
    private function verifiedState(string $state): array
    {
        if (empty($state)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Missing state parameter. Please restart the installation from the Appwrite Console.');
        }

        $signingKey = System::getEnv('_APP_OPENSSL_KEY_V1', '');
        if (empty($signingKey)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Signing key is not configured. Please configure _APP_OPENSSL_KEY_V1 in .env file.');
        }

        $decoded = \json_decode($this->decodeState($state), true);
        $decoded = \is_array($decoded) ? $decoded : [];

        $signature = \hash_hmac('sha256', \json_encode([$decoded['projectId'] ?? '', $decoded['success'] ?? '', $decoded['failure'] ?? '']), $signingKey);
        if (!\hash_equals($signature, \is_string($decoded['signature'] ?? null) ? $decoded['signature'] : '')) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid state parameter. Please restart the installation from the Appwrite Console.');
        }

        return $decoded;
    }

    /**
     * The signed JSON the Authorize action put in the state. It travels
     * base64url-encoded, since Entire expects an opaque state token; a state
     * that is not base64url is taken as the JSON itself.
     */
    private function decodeState(string $state): string
    {
        $decoded = \base64_decode(\strtr($state, '-_', '+/'), true);

        return $decoded !== false && \str_starts_with($decoded, '{') ? $decoded : $state;
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
