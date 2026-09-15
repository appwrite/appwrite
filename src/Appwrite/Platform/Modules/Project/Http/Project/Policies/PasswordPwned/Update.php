<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordPwned;

use Appwrite\Event\Event;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Domains\Validator\PublicDomain;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\AnyOf;
use Utopia\Validator\Boolean;
use Utopia\Validator\Multiple;
use Utopia\Validator\Range;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectPasswordPwnedPolicy';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/policies/password-pwned')
            ->desc('Update password pwned policy')
            ->groups(['api', 'project'])
            ->label('scope', ['policies.write', 'project.policies.write'])
            ->label('event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.resource', 'project/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'policies',
                name: 'updatePasswordPwnedPolicy',
                description: <<<EOT
                Updating this policy allows you to control if passwords are checked against the Have I Been Pwned breach database. When enabled, a password that appears in at least `threshold` known breaches cannot be set or changed. Enable `sessions` to check passwords on sign-in too. Only the first five characters of the password hash are ever shared with the service. Options left out keep their current value.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
            ))
            ->param('enabled', null, new Boolean(), 'Toggle password pwned policy. Set to true to block passwords exposed in known data breaches, or false to allow them. Default is true. When changing this policy, existing passwords remain valid.', optional: true)
            ->param('endpoint', null, new AnyOf([new WhiteList([''], true), new Multiple([new URL(['http', 'https']), new PublicDomain()], Multiple::TYPE_STRING)], AnyOf::TYPE_STRING), 'Custom endpoint of a Have I Been Pwned compatible range API, for example a self-hosted mirror. Pass an empty string to use the server default.', optional: true)
            ->param('threshold', null, new Range(1, PHP_INT_MAX), 'Minimum number of known breaches a password must appear in before it is rejected. Default is 1.', optional: true)
            ->param('sessions', null, new Boolean(), 'Whether passwords are checked when a session is created. When enabled, signing in records whether the password appears in a known data breach. Default is false.', optional: true)
            ->param('forceReset', null, new Boolean(), 'Whether signing in with a breached password is blocked until the password is reset. Only applies when sessions are checked. Default is false, which allows the sign-in.', optional: true)
            ->param('failClosed', null, new Boolean(), 'Whether passwords are rejected when the breach service cannot be reached. Default is true. Set to false to skip the check instead.', optional: true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        ?bool $enabled,
        ?string $endpoint,
        ?int $threshold,
        ?bool $sessions,
        ?bool $forceReset,
        ?bool $failClosed,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        Event $queueForEvents,
    ): void {
        $auths = $project->getAttribute('auths', []);
        $auths['passwordPwned'] = \array_merge([
            'enabled' => true,
            'endpoint' => '',
            'threshold' => 1,
            'sessions' => false,
            'forceReset' => false,
            'failClosed' => true,
        ], $auths['passwordPwned'] ?? []);

        if ($enabled !== null) {
            $auths['passwordPwned']['enabled'] = $enabled;
        }
        if ($endpoint !== null) {
            $auths['passwordPwned']['endpoint'] = $endpoint;
        }
        if ($threshold !== null) {
            $auths['passwordPwned']['threshold'] = $threshold;
        }
        if ($sessions !== null) {
            $auths['passwordPwned']['sessions'] = $sessions;
        }
        if ($forceReset !== null) {
            $auths['passwordPwned']['forceReset'] = $forceReset;
        }
        if ($failClosed !== null) {
            $auths['passwordPwned']['failClosed'] = $failClosed;
        }

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
            'auths' => $auths,
        ])));
        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents
            ->setParam('projectId', $project->getId())
            ->setParam('policy', 'password-pwned');

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
