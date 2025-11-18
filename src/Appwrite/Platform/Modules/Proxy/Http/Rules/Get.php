<?php

namespace Appwrite\Platform\Modules\Proxy\Http\Rules;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Proxy\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getRule';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/proxy/rules/:ruleId')
            ->desc('Get rule')
            ->groups(['api', 'proxy'])
            ->label('scope', 'rules.read')
            ->label('sdk', new Method(
                namespace: 'proxy',
                group: null,
                name: 'getRule',
                description: <<<EOT
                Get a proxy rule by its unique ID.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROXY_RULE,
                    )
                ]
            ))
            ->param('ruleId', '', new UID(), 'Rule ID.')
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(
        string $ruleId,
        Response $response,
        Document $project,
        Database $dbForPlatform
    ) {
        $rule = $dbForPlatform->getDocument('rules', $ruleId);

        if ($rule->isEmpty() || $rule->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::RULE_NOT_FOUND);
        }

        // Fill response model
        $certificate = $dbForPlatform->getDocument('certificates', $rule->getAttribute('certificateId', ''));

        // Merge logs: priority to certificate logs if both have values, otherwise use whichever is not empty
        $ruleLogs = $rule->getAttribute('logs', '');
        $certificateLogs = $certificate->getAttribute('logs', '');
        $logs = '';
        if (!empty($certificateLogs) && !empty($ruleLogs)) {
            $logs = $certificateLogs; // Certificate logs have priority
        } elseif (!empty($certificateLogs)) {
            $logs = $certificateLogs;
        } elseif (!empty($ruleLogs)) {
            $logs = $ruleLogs;
        }
        $rule->setAttribute('logs', $logs);
        $rule->setAttribute('renewAt', $certificate->getAttribute('renewDate', ''));

        $response->dynamic($rule, Response::MODEL_PROXY_RULE);
    }
}
