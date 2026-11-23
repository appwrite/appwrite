<?php

namespace Appwrite\Platform\Modules\Alerts\Http\Escalations;

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
        return 'getEscalation';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/organization/escalations/:escalationId')
            ->desc('Get escalation')
            ->groups(['api', 'organization', 'escalations'])
            ->label('scope', 'monitors.read')
            ->label('sdk', new Method(
                namespace: 'organization',
                group: 'escalations',
                name: 'getEscalation',
                description: <<<EOT
                Get one of your organization's escalations by its ID.
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
        $response->dynamic($this->getEscalation($dbForPlatform, $authorization, $team, $escalationId), Response::MODEL_ESCALATION);
    }
}
