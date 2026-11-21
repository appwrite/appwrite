<?php

namespace Appwrite\Platform\Modules\Alerts\Http\EscalationPolicies;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Get extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'getEscalationPolicy';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/organization/escalation-policies/:escalationPolicyId')
            ->desc('Get escalation policy')
            ->groups(['api', 'organization', 'escalationPolicies'])
            ->label('scope', 'monitors.read')
            ->label('sdk', new Method(
                namespace: 'organization',
                group: 'escalationPolicies',
                name: 'getEscalationPolicy',
                description: <<<EOT
                Get one of your organization's escalation policies by its ID.
                EOT,
                auth: [AuthType::ADMIN, AuthType::ORGANIZATION],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ESCALATION_POLICY,
                    ),
                ]
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
        $response->dynamic($this->getPolicy($dbForPlatform, $authorization, $team, $escalationPolicyId), Response::MODEL_ESCALATION_POLICY);
    }
}
