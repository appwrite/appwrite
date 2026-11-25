<?php

namespace Appwrite\Platform\Modules\Alerts\Http\Escalations;

use Appwrite\Alert\Escalation;
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
        return 'updateEscalation';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/organization/escalations/:escalationId')
            ->desc('Update escalation')
            ->groups(['api', 'organization', 'escalations'])
            ->label('scope', ['escalations.write', 'monitors.write'])
            ->label('audits.event', 'escalation.update')
            ->label('audits.resource', 'escalation/{request.escalationId}')
            ->label('sdk', new Method(
                namespace: 'organization',
                group: 'escalations',
                name: 'updateEscalation',
                description: <<<EOT
                Update an escalation's name, steps or repeats; what you leave out stays as it is. Alerts already escalating keep the steps they started with; the next alert uses the new ones.
                EOT,
                auth: [AuthType::ADMIN, AuthType::ORGANIZATION],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ESCALATION,
                    ),
                ]
            ))
            ->param('escalationId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getMaxUidLength()), 'Escalation ID.', false, ['dbForPlatform'])
            ->param('name', null, new Nullable(new Text(128)), 'Escalation name. Max length: 128 chars.', true)
            ->param('steps', null, new Nullable(new ArrayList(new JSON(), APP_LIMIT_ARRAY_PARAMS_SIZE)), 'Steps notified in order while nobody acknowledges the alert. Each step is an object with `delay` (seconds to wait before the step, 0 to ' . Escalation::MAX_DELAY . ', default 0), `roles` (organization roles such as `owner` or `developer`) and `userIds` (organization members who accepted their invitation). A step names each role and member at most once and needs at least one of `roles` or `userIds`. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' steps.', true, example: '[{"delay":0,"roles":["owner"]},{"delay":900,"roles":["developer"]}]')
            ->param('repeat', null, new Nullable(new Range(0, Escalation::MAX_REPEAT)), 'How many more times the steps run from the first while nobody acknowledges the alert, between 0 and ' . Escalation::MAX_REPEAT . '. Each round starts a monitor\'s cooldown after the last step.', true)
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
        string $escalationId,
        ?string $name,
        ?array $steps,
        ?int $repeat,
        Response $response,
        Document $team,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        $escalation = $this->getEscalation($dbForPlatform, $authorization, $team, $escalationId);
        $changes = new Document();

        if ($steps !== null || $repeat !== null) {
            $parsed = $steps === null
                ? new Escalation(Escalation::fromDocument($escalation)->steps, (int) $repeat)
                : $this->parseEscalation($steps, $repeat ?? (int) $escalation->getAttribute('repeat', 0), $dbForPlatform, $authorization, $team);
            if ($steps !== null) {
                $changes->setAttribute('steps', $parsed->toArray());
            }
            if ($repeat !== null) {
                $changes->setAttribute('repeat', $parsed->repeat);
            }
        }

        if ($name !== null) {
            $changes->setAttribute('name', $name);
            $changes->setAttribute('search', $this->search($escalation->getId(), $name));
        }

        if (!$changes->isEmpty()) {
            $escalation = $authorization->skip(fn () => $dbForPlatform->updateDocument('escalations', $escalation->getId(), $changes));
        }

        $response->dynamic($escalation, Response::MODEL_ESCALATION);
    }
}
