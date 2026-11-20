<?php

namespace Appwrite\Platform\Modules\Alerts\Http\EscalationPolicies;

use Appwrite\Alert\Policy;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\JSON;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Update extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateEscalationPolicy';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/organization/escalation-policies/:escalationPolicyId')
            ->desc('Update escalation policy')
            ->groups(['api', 'organization', 'escalationPolicies'])
            ->label('scope', 'monitors.write')
            ->label('audits.event', 'escalationPolicy.update')
            ->label('audits.resource', 'escalationPolicy/{request.escalationPolicyId}')
            ->label('sdk', new Method(
                namespace: 'organization',
                group: 'escalationPolicies',
                name: 'updateEscalationPolicy',
                description: <<<EOT
                Update an escalation policy's name, steps or repeats; what you leave out stays as it is. Alerts already escalating go on with the new steps from the step they reached.
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
            ->param('name', null, new Nullable(new Text(128)), 'Escalation policy name. Max length: 128 chars.', true)
            ->param('steps', null, new Nullable(new ArrayList(new JSON(), APP_LIMIT_ARRAY_PARAMS_SIZE)), 'Steps notified in order while nobody acknowledges the alert, each an object with `delay` (seconds to wait first, 0 to ' . Policy::MAX_DELAY . '), and `roles` (organization roles such as `owner` or `developer`), `userIds` (organization members) or both. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' steps.', true)
            ->param('repeat', null, new Nullable(new Range(0, Policy::MAX_REPEAT)), 'How many more times the steps run from the first while nobody acknowledges the alert, between 0 and ' . Policy::MAX_REPEAT . '.', true)
            ->inject('response')
            ->inject('team')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * @param array<mixed>|null $steps
     */
    public function action(
        string $escalationPolicyId,
        ?string $name,
        ?array $steps,
        ?int $repeat,
        Response $response,
        Document $team,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        $policy = $this->getPolicy($dbForPlatform, $authorization, $team, $escalationPolicyId);
        $changes = new Document();

        if ($steps !== null || $repeat !== null) {
            $parsed = $steps === null
                ? new Policy(Policy::fromDocument($policy)->steps, (int) $repeat)
                : $this->parsePolicy($steps, $repeat ?? (int) $policy->getAttribute('repeat', 0), $dbForPlatform, $authorization, $team);
            if ($steps !== null) {
                $changes->setAttribute('steps', $parsed->toArray());
            }
            if ($repeat !== null) {
                $changes->setAttribute('repeat', $parsed->repeat);
            }
        }

        if ($name !== null) {
            $changes->setAttribute('name', $name);
            $changes->setAttribute('search', $this->search($policy->getId(), $name));
        }

        if (!$changes->isEmpty()) {
            $policy = $authorization->skip(fn () => $dbForPlatform->updateDocument('escalationPolicies', $policy->getId(), $changes));
        }

        $response->dynamic($policy, Response::MODEL_ESCALATION_POLICY);
    }
}
