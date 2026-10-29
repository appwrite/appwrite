<?php

namespace Appwrite\Platform\Modules\Alerts\Http\Rules;

use Appwrite\Platform\Modules\Alerts\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Delete extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteRule';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/alerts/rules/:ruleId')
            ->desc('Delete alert rule')
            ->groups(['api', 'alerts'])
            ->label('scope', 'alertRules.write')
            ->label('audits.event', 'alertRule.delete')
            ->label('audits.resource', 'alertRule/{request.ruleId}')
            ->label('sdk', new Method(
                namespace: 'alerts',
                group: 'rules',
                name: 'deleteRule',
                description: <<<EOT
                Delete an alert rule by its unique ID. The rule stops being evaluated and sends no further notifications.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    ),
                ],
                contentType: ContentType::NONE
            ))
            ->param('ruleId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getMaxUidLength()), 'Rule ID.', false, ['dbForPlatform'])
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $ruleId,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        $rule = $this->getRule($dbForPlatform, $authorization, $project, $ruleId);

        $authorization->skip(fn () => $dbForPlatform->deleteDocument('alertRules', $rule->getId()));
        $this->unschedule($dbForPlatform, $rule);

        $response->noContent();
    }
}
