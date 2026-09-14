<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Policies\OAuthTrustProviderEmail;

use Appwrite\Event\Event;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectOAuthTrustProviderEmailPolicy';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/policies/oauth-trust-provider-email')
            ->desc('Update OAuth trust provider email policy')
            ->groups(['api', 'project'])
            ->label('scope', ['policies.write', 'project.policies.write'])
            ->label('event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.resource', 'project/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'policies',
                name: 'updateOauthTrustProviderEmailPolicy',
                description: <<<'EOT'
                By default Appwrite only links a new OAuth sign-in to an existing account when the provider reports the email as verified. The Microsoft provider does not report the email as verified, so same-email linking fails. Enabling this policy lets a project deliberately trust the email the provider reports and allow linking even when the provider does not flag it as verified.

                Trust boundary: successful authentication only proves control of the provider identity, not verified ownership of every reported address. Only enable this for providers and directories you trust (for example your own Microsoft tenant with accounts provisioned by IT).

                This policy only affects linking an OAuth sign-in to an existing account by email. It does not mark the user's email as verified in Appwrite: the `emailVerification` flag stays driven by the provider verification status, so account features that require Appwrite-side email verification still enforce it.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    ),
                ],
            ))
            ->param('enabled', null, new Boolean, 'Toggle the OAuth trust provider email policy. Set to true to allow linking an OAuth sign-in to an existing account by email even when the provider does not report the email as verified, or false to keep requiring a verified email.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        bool $enabled,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        Event $queueForEvents,
    ): void {
        $auths = $project->getAttribute('auths', []);
        $auths['oauthTrustProviderEmail'] = $enabled;

        $updates = new Document([
            'auths' => $auths,
        ]);

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $updates));
        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents
            ->setParam('projectId', $project->getId())
            ->setParam('policy', 'oauth-trust-provider-email');

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
