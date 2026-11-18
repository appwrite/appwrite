<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordPersonalData;

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
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectPasswordPersonalDataPolicy';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/policies/password-personal-data')
            ->httpAlias('/v1/projects/:projectId/auth/personal-data')
            ->desc('Update password personal data policy')
            ->groups(['api', 'project'])
            ->label('scope', ['policies.write', 'project.policies.write'])
            ->label('event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.resource', 'project/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'policies',
                name: 'updatePasswordPersonalDataPolicy',
                description: <<<EOT
                Updating this policy allows you to control if password strength is checked against personal data. When enabled, and user sets or changes their password, the password must not contain user ID, name, email or phone number.

                Custom denyList entries use case-insensitive substring matching and apply only while this policy is enabled. Omit denyList or pass null to preserve it, pass an array to replace it, or pass [] to clear it. Entries are stored without trimming or deduplication. Existing passwords remain valid for sign-in and password confirmation. The combined serialized project authentication configuration must fit within 16,384 characters, even when each list meets its entry limits.
                Password recovery also checks the recovered user profile, closing the previous gap in profile-based personal-data validation.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
            ))
            // TODO: Split into more toggles, simiplar to membership privacy policy
            ->param('enabled', null, new Boolean(), 'Toggle password personal data policy. Set to true if you want to block passwords including user\'s personal data, or false to allow it. When changing this policy, existing passwords remain valid.')
            ->param('denyList', null, new Nullable(new ArrayList(new Text(256, 1), 100)), 'Custom blocked values using case-insensitive substring matching. Up to 100 entries of 1 to 256 characters. Omit or pass null to preserve, an array to replace, or [] to clear. Combined authentication settings must fit within 16,384 characters.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        bool $enabled,
        ?array $denyList,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        Event $queueForEvents,
    ): void {
        $auths = $project->getAttribute('auths', []);
        $auths['personalDataCheck'] = $enabled;
        if ($denyList !== null) {
            $auths['personalDataDenyList'] = $denyList;
        }

        $updates = new Document([
            'auths' => $auths,
        ]);

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $updates));
        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents
            ->setParam('projectId', $project->getId())
            ->setParam('policy', 'password-personal-data');

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
