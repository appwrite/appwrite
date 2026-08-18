<?php

namespace Appwrite\Platform\Modules\VCS\Http\Origin\Callback;

use Appwrite\Auth\OAuth2\Cursor as OAuth2Cursor;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Permission as AppwritePermission;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
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
            ->param('installation_receipt', '', new Text(4096, 0), 'Origin installation receipt JWT, signed by Cursor.', true)
            ->param('state', '', new Text(2048), 'Origin state. Contains info sent when starting the installation flow.', true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('platform')
            ->callback($this->action(...));
    }

    public function action(
        string $providerInstallationId,
        string $installationReceipt,
        string $state,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        array $platform
    ) {
        // TODO: Temporary debug logging while the Origin integration is verified -- remove afterwards.
        $params = $request->getParams();
        Console::log('[ORIGIN DEBUG] Callback received');
        Console::log('[ORIGIN DEBUG] Callback params: ' . \json_encode($params));
        Console::log('[ORIGIN DEBUG] Callback headers: ' . \json_encode($request->getHeaders()));
        Console::log('[ORIGIN DEBUG] Callback installation_id: "' . $providerInstallationId . '"');

        // Surface anything that looks like an OAuth2 token flow (e.g. a code
        // exchange), since Origin is not expected to send one.
        $oauthParams = \array_intersect_key($params, \array_flip([
            'code', 'token', 'access_token', 'refresh_token', 'expires_in', 'token_type', 'scope', 'id_token', 'setup_action', 'authuser', 'session_state',
        ]));
        if (!empty($oauthParams)) {
            Console::log('[ORIGIN DEBUG] Possible OAuth2 flow params detected: ' . \json_encode($oauthParams));
        } else {
            Console::log('[ORIGIN DEBUG] No OAuth2 flow params detected');
        }

        // TODO: Temporary debug logging while the Origin integration is verified -- remove afterwards.
        // Decode only -- the EdDSA signature is not verified, so the claims
        // must not be trusted for authorization decisions yet.
        if (!empty($installationReceipt)) {
            $segments = \explode('.', $installationReceipt);
            if (\count($segments) === 3) {
                $receiptHeader = \json_decode(\base64_decode(\strtr($segments[0], '-_', '+/')), true) ?? [];
                $receiptClaims = \json_decode(\base64_decode(\strtr($segments[1], '-_', '+/')), true) ?? [];
                Console::log('[ORIGIN DEBUG] Receipt JWT header: ' . \json_encode($receiptHeader));
                Console::log('[ORIGIN DEBUG] Receipt JWT claims: ' . \json_encode($receiptClaims));
            } else {
                Console::log('[ORIGIN DEBUG] installation_receipt is not a JWT: ' . $installationReceipt);
            }
        } else {
            Console::log('[ORIGIN DEBUG] No installation_receipt received');
        }

        if (empty($state)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Missing state parameter. Please restart the installation from the Appwrite Console.');
        }

        $state = \json_decode($state, true) ?? [];
        $redirectFailure = $state['failure'] ?? '';
        $projectId = $state['projectId'] ?? '';

        // TODO: Temporary debug logging while the Origin integration is verified -- remove afterwards.
        Console::log('[ORIGIN DEBUG] Decoded state: ' . \json_encode($state));

        // This endpoint is public and Origin performs no token exchange --
        // without verifying the signature the Authorize action put in state,
        // anyone could pass an arbitrary projectId here and attach an
        // installation to another project.
        $signature = \hash_hmac('sha256', \json_encode([$projectId, $state['success'] ?? '', $redirectFailure]), System::getEnv('_APP_OPENSSL_KEY_V1', ''));
        if (!\hash_equals($signature, $state['signature'] ?? '')) {
            // TODO: Temporary debug logging while the Origin integration is verified -- remove afterwards.
            Console::log('[ORIGIN DEBUG] State signature mismatch, rejecting callback');
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid state parameter. Please restart the installation from the Appwrite Console.');
        }
        Console::log('[ORIGIN DEBUG] State signature valid');

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

        // Authenticate the callback by verifying the installation receipt
        // against Cursor's published JWKS. This proves the request genuinely
        // originates from Origin (the callback is public and has no token
        // exchange) before any installation is created.
        // The Cursor adapter expects its secret as a JSON object.
        $oauth2 = new OAuth2Cursor(
            System::getEnv('_APP_VCS_ORIGIN_CLIENT_ID', ''),
            \json_encode(['privateKey' => System::getEnv('_APP_VCS_ORIGIN_PRIVATE_KEY', '')]),
            ''
        );

        try {
            $receiptClaims = $oauth2->verifyReceipt($installationReceipt);

            // Bind the receipt to the installation id in the callback so a
            // valid receipt cannot be replayed to attach a different
            // installation.
            if (($receiptClaims['sub'] ?? '') !== $providerInstallationId) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Receipt subject does not match installation ID');
            }
        } catch (\Throwable $e) {
            Console::log('[ORIGIN DEBUG] Receipt verification failed: ' . $e->getMessage());
            $this->failure($response, $redirectFailure, 'Could not verify the Origin installation receipt.');
            return;
        }

        // The Cursor namespace (team/workspace) owning the installation.
        $organization = $receiptClaims['namespace_id'] ?? '';
        Console::log('[ORIGIN DEBUG] Receipt verified, namespace_id: "' . $organization . '"');

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

            // TODO: Temporary debug logging while the Origin integration is verified -- remove afterwards.
            Console::log('[ORIGIN DEBUG] Created installation "' . $installation->getId() . '" for project "' . $projectId . '"');
        } else {
            $installation = $dbForPlatform->updateDocument('installations', $installation->getId(), new Document([
                'organization' => $organization,
            ]));

            Console::log('[ORIGIN DEBUG] Installation already exists: "' . $installation->getId() . '"');
        }

        Console::log('[ORIGIN DEBUG] Redirecting to success URL: ' . $redirectSuccess);

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
        // TODO: Temporary debug logging while the Origin integration is verified -- remove afterwards.
        Console::log('[ORIGIN DEBUG] Callback failed: ' . $error . ' (redirect: "' . $redirect . '")');

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
