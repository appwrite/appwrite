<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Policies\MFAFactors;

use Appwrite\Event\Event;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectMFAFactorsPolicy';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/policies/mfa-factors')
            ->desc('Update MFA factors policy')
            ->groups(['api', 'project'])
            ->label('scope', ['policies.write', 'project.policies.write'])
            ->label('event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.resource', 'project/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'policies',
                name: 'updateMFAFactorsPolicy',
                description: <<<EOT
                Updating this policy allows you to control which factors users can use to complete an MFA challenge. Disabled factors cannot be used to create a challenge and are reported as unavailable when listing factors. The custom factor is disabled by default; enable it to deliver challenge codes through your own channel. Recovery codes always remain available as a fallback.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
            ))
            ->param('totp', null, new Boolean(), 'Set to true to allow TOTP to complete an MFA challenge, or false to disable it.', optional: true)
            ->param('email', null, new Boolean(), 'Set to true to allow email to complete an MFA challenge, or false to disable it.', optional: true)
            ->param('phone', null, new Boolean(), 'Set to true to allow phone (SMS) to complete an MFA challenge, or false to disable it.', optional: true)
            ->param('custom', null, new Boolean(), 'Set to true to allow the custom factor to complete an MFA challenge, or false to disable it.', optional: true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        ?bool $totp,
        ?bool $email,
        ?bool $phone,
        ?bool $custom,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Event $queueForEvents,
    ): void {
        $auths = $project->getAttribute('auths', []);
        $factors = $auths['mfaFactors'] ?? [];

        if ($totp !== null) {
            $factors['totp'] = $totp;
        }
        if ($email !== null) {
            $factors['email'] = $email;
        }
        if ($phone !== null) {
            $factors['phone'] = $phone;
        }
        if ($custom !== null) {
            $factors['custom'] = $custom;
        }

        $auths['mfaFactors'] = $factors;

        $updates = new Document([
            'auths' => $auths,
        ]);

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $updates);
        $dbForPlatform->purgeCachedDocument('projects', $project->getId());

        $queueForEvents
            ->setParam('projectId', $project->getId())
            ->setParam('policy', 'mfa-factors');

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
