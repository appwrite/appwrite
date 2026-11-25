<?php

namespace Appwrite\Platform\Modules\Alerts\Http\Escalations;

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
        return 'deleteEscalation';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/organization/escalations/:escalationId')
            ->desc('Delete escalation')
            ->groups(['api', 'organization', 'escalations'])
            ->label('scope', 'escalations.write')
            ->label('audits.event', 'escalation.delete')
            ->label('audits.resource', 'escalation/{request.escalationId}')
            ->label('sdk', new Method(
                namespace: 'organization',
                group: 'escalations',
                name: 'deleteEscalation',
                description: <<<EOT
                Delete an escalation. An escalation that a monitor uses cannot be deleted: move the monitor to another escalation or to `notify` roles first. Open alerts keep the steps they started with.
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
            ->param('escalationId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getMaxUidLength()), 'Escalation ID.', false, ['dbForPlatform'])
            ->inject('response')
            ->inject('team')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $escalationId,
        Response $response,
        Document $team,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        $escalation = $this->getEscalation($dbForPlatform, $authorization, $team, $escalationId);

        $monitor = $authorization->skip(fn () => $dbForPlatform->findOne('monitors', [
            Query::equal('escalationId', [$escalation->getId()]),
        ]));
        if (!$monitor->isEmpty()) {
            throw new Exception(Exception::ESCALATION_IN_USE, "Escalation '{$escalation->getId()}' is used by monitor '{$monitor->getId()}'.");
        }

        $authorization->skip(fn () => $dbForPlatform->deleteDocument('escalations', $escalation->getId()));

        $response->noContent();
    }
}
