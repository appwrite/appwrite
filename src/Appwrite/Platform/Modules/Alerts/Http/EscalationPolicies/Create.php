<?php

namespace Appwrite\Platform\Modules\Alerts\Http\EscalationPolicies;

use Appwrite\Alert\Policy;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Id;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\JSON;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Create extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'createEscalationPolicy';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/organization/escalation-policies')
            ->desc('Create escalation policy')
            ->groups(['api', 'organization', 'escalationPolicies'])
            ->label('scope', 'monitors.write')
            ->label('audits.event', 'escalationPolicy.create')
            ->label('audits.resource', 'escalationPolicy/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'organization',
                group: 'escalationPolicies',
                name: 'createEscalationPolicy',
                description: <<<EOT
                Create an escalation policy for your organization. A monitor using it notifies the policy's steps one after another while its alert fires, each by email, push and in the console, until a member acknowledges the alert or it resolves. Each step waits its `delay` in seconds, after the alert fires for the first step and after the previous step for the others, then notifies the members holding its `roles` and those named in `userIds`. With `repeat`, the steps run again from the first, a monitor's cooldown after the last step.
                EOT,
                auth: [AuthType::ADMIN, AuthType::ORGANIZATION],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_ESCALATION_POLICY,
                    ),
                ]
            ))
            ->param('escalationPolicyId', '', fn (Database $dbForPlatform) => new CustomId(false, $dbForPlatform->getMaxUidLength()), 'Escalation policy ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForPlatform'])
            ->param('name', '', new Text(128), 'Escalation policy name. Max length: 128 chars.')
            ->param('steps', [], new ArrayList(new JSON(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Steps notified in order while nobody acknowledges the alert, each an object with `delay` (seconds to wait first, 0 to ' . Policy::MAX_DELAY . '), and `roles` (organization roles such as `owner` or `developer`), `userIds` (organization members) or both. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' steps.')
            ->param('repeat', 0, new Range(0, Policy::MAX_REPEAT), 'How many more times the steps run from the first while nobody acknowledges the alert, between 0 and ' . Policy::MAX_REPEAT . '. Each round starts a monitor\'s cooldown after the last step.', true)
            ->inject('response')
            ->inject('team')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * @param array<mixed> $steps
     */
    public function action(
        string $escalationPolicyId,
        string $name,
        array $steps,
        int $repeat,
        Response $response,
        Document $team,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        $policy = $this->parsePolicy($steps, $repeat, $dbForPlatform, $authorization, $team);
        $escalationPolicyId = $escalationPolicyId === 'unique()' ? Id::unique() : $escalationPolicyId;

        try {
            $document = $authorization->skip(fn () => $dbForPlatform->createDocument('escalationPolicies', new Document([
                '$id' => $escalationPolicyId,
                '$permissions' => [],
                'name' => $name,
                'teamId' => $team->getId(),
                'teamInternalId' => (string) $team->getSequence(),
                'steps' => $policy->toArray(),
                'repeat' => $policy->repeat,
                'search' => $this->search($escalationPolicyId, $name),
            ])));
        } catch (DuplicateException) {
            throw new Exception(Exception::ESCALATION_POLICY_ALREADY_EXISTS);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($document, Response::MODEL_ESCALATION_POLICY);
    }
}
