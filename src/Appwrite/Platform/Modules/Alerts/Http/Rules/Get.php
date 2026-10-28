<?php

namespace Appwrite\Platform\Modules\Alerts\Http\Rules;

use Appwrite\Platform\Modules\Alerts\Base;
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
        return 'getRule';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/alerts/rules/:ruleId')
            ->desc('Get alert rule')
            ->groups(['api', 'alerts'])
            ->label('scope', 'alertRules.read')
            ->label('sdk', new Method(
                namespace: 'alerts',
                group: 'rules',
                name: 'getRule',
                description: <<<EOT
                Get an alert rule by its unique ID, with its state as of its last evaluation.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ALERT_RULE,
                    ),
                ]
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
        $rule->removeAttribute('webhookSecret');

        $response->dynamic($rule, Response::MODEL_ALERT_RULE);
    }
}
