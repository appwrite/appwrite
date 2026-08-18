<?php

namespace Appwrite\Platform\Modules\VCS\Http\Codebase\Callback;

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
        return 'getVCSCodebaseCallback';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vcs/codebase/callback')
            ->desc('Get installation from Codebase app')
            ->groups(['api', 'vcs'])
            ->label('scope', 'public')
            ->label('error', APP_VIEWS_DIR . '/general/error.phtml')
            ->param('installation_id', '', new Text(256, 0), 'Codebase installation ID', true)
            ->param('state', '', new Text(2048), 'Codebase state. Contains info sent when starting the installation flow.', true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('platform')
            ->callback($this->action(...));
    }

    public function action(
        string $providerInstallationId,
        string $state,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        array $platform
    ) {
        // TODO: Temporary debug logging while the Codebase integration is verified -- remove afterwards.
        $params = $request->getParams();
        Console::log('[CODEBASE DEBUG] Callback received');
        Console::log('[CODEBASE DEBUG] Callback params: ' . \json_encode($params));
        Console::log('[CODEBASE DEBUG] Callback headers: ' . \json_encode($request->getHeaders()));
        Console::log('[CODEBASE DEBUG] Callback installation_id: "' . $providerInstallationId . '"');

        // Surface anything that looks like an OAuth2 token flow (e.g. a code
        // exchange), since Codebase is not expected to send one.
        $oauthParams = \array_intersect_key($params, \array_flip([
            'code', 'token', 'access_token', 'refresh_token', 'expires_in', 'token_type', 'scope', 'id_token', 'setup_action', 'authuser', 'session_state',
        ]));
        if (!empty($oauthParams)) {
            Console::log('[CODEBASE DEBUG] Possible OAuth2 flow params detected: ' . \json_encode($oauthParams));
        } else {
            Console::log('[CODEBASE DEBUG] No OAuth2 flow params detected');
        }

        if (empty($state)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Missing state parameter. Please restart the installation from the Appwrite Console.');
        }

        $state = \json_decode($state, true) ?? [];
        $redirectFailure = $state['failure'] ?? '';
        $projectId = $state['projectId'] ?? '';

        // TODO: Temporary debug logging while the Codebase integration is verified -- remove afterwards.
        Console::log('[CODEBASE DEBUG] Decoded state: ' . \json_encode($state));

        // This endpoint is public and Codebase performs no token exchange --
        // without verifying the signature the Authorize action put in state,
        // anyone could pass an arbitrary projectId here and attach an
        // installation to another project.
        $signature = \hash_hmac('sha256', \json_encode([$projectId, $state['success'] ?? '', $redirectFailure]), System::getEnv('_APP_OPENSSL_KEY_V1', ''));
        if (!\hash_equals($signature, $state['signature'] ?? '')) {
            // TODO: Temporary debug logging while the Codebase integration is verified -- remove afterwards.
            Console::log('[CODEBASE DEBUG] State signature mismatch, rejecting callback');
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid state parameter. Please restart the installation from the Appwrite Console.');
        }
        Console::log('[CODEBASE DEBUG] State signature valid');

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
            $this->failure($response, $redirectFailure, 'Codebase installation ID is missing.');
            return;
        }

        $projectInternalId = $project->getSequence();

        $installation = $dbForPlatform->findOne('installations', [
            Query::equal('providerInstallationId', [$providerInstallationId]),
            Query::equal('projectInternalId', [$projectInternalId]),
            Query::equal('provider', ['codebase']),
        ]);

        if ($installation->isEmpty()) {
            $teamId = $project->getAttribute('teamId', '');

            $installation = $dbForPlatform->createDocument('installations', new Document([
                '$id' => ID::unique(),
                '$permissions' => $this->getPermissions($teamId, $projectId),
                'providerInstallationId' => $providerInstallationId,
                'projectId' => $projectId,
                'projectInternalId' => $projectInternalId,
                'provider' => 'codebase',
                'organization' => '',
                'personal' => false,
            ]));

            // TODO: Temporary debug logging while the Codebase integration is verified -- remove afterwards.
            Console::log('[CODEBASE DEBUG] Created installation "' . $installation->getId() . '" for project "' . $projectId . '"');
        } else {
            Console::log('[CODEBASE DEBUG] Installation already exists: "' . $installation->getId() . '"');
        }

        Console::log('[CODEBASE DEBUG] Redirecting to success URL: ' . $redirectSuccess);

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
        // TODO: Temporary debug logging while the Codebase integration is verified -- remove afterwards.
        Console::log('[CODEBASE DEBUG] Callback failed: ' . $error . ' (redirect: "' . $redirect . '")');

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
