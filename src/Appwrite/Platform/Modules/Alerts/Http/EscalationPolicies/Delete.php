<?php

namespace Appwrite\Platform\Modules\Alerts\Http\EscalationPolicies;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Delete extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteEscalationPolicy';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/organization/escalation-policies/:escalationPolicyId')
            ->desc('Delete escalation policy')
            ->groups(['api', 'organization', 'escalationPolicies'])
            ->label('scope', 'monitors.write')
            ->label('audits.event', 'escalationPolicy.delete')
            ->label('audits.resource', 'escalationPolicy/{request.escalationPolicyId}')
            ->label('sdk', new Method(
                namespace: 'organization',
                group: 'escalationPolicies',
                name: 'deleteEscalationPolicy',
                description: <<<EOT
                Delete an escalation policy. A policy that a monitor uses cannot be deleted: move the monitor to another policy or to `notify` roles first. Alerts still escalating through it stop escalating.
                EOT,
                auth: [AuthType::ADMIN, AuthType::ORGANIZATION],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    ),
                ],
                contentType: ContentType::NONE,
            ))
            ->param('escalationPolicyId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getMaxUidLength()), 'Escalation policy ID.', false, ['dbForPlatform'])
            ->inject('response')
            ->inject('team')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $escalationPolicyId,
        Response $response,
        Document $team,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        $policy = $this->getPolicy($dbForPlatform, $authorization, $team, $escalationPolicyId);

        $monitor = $authorization->skip(fn () => $dbForPlatform->findOne('monitors', [
            Query::equal('escalationPolicyId', [$policy->getId()]),
        ]));
        if (!$monitor->isEmpty()) {
            throw new Exception(Exception::ESCALATION_POLICY_IN_USE, "Escalation policy '{$policy->getId()}' is used by monitor '{$monitor->getId()}'.");
        }

        $authorization->skip(fn () => $dbForPlatform->deleteDocument('escalationPolicies', $policy->getId()));

        $response->noContent();
    }
}
