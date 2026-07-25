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
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getRule';
    }

    public function __construct(...$params)
    {
        parent::__construct(...$params);

        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/proxy/rules/:ruleId')
            ->desc('Get rule')
            ->groups(['api', 'proxy'])
            ->label('scope', 'rules.read')
            ->label('sdk', new Method(
                namespace: 'proxy',
                group: 'rules',
                name: 'getRule',
                description: <<<EOT
                Get a proxy rule by its unique ID.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROXY_RULE,
                    )
                ]
            ))
            ->param('ruleId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Rule ID.', false, ['dbForProject'])
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
    ) {
        $rule = $authorization->skip(fn () => $dbForPlatform->getDocument('rules', $ruleId));

        if ($rule->isEmpty() || $rule->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::RULE_NOT_FOUND);
        }

        $certificate = $authorization->skip(fn () => $dbForPlatform->getDocument('certificates', $rule->getAttribute('certificateId', '')));

        // Give priority to certificate generation logs if present
        if (!empty($certificate->getAttribute('logs', ''))) {
            $rule->setAttribute('logs', $certificate->getAttribute('logs', ''));
        }

        $rule->setAttribute('renewAt', $certificate->getAttribute('renewDate', ''));

        // Rename 'created' status to 'unverified' for consistency.
        // 'verifying' and 'verified' statuses stay as is.
        // 'unverified' in the meaning of failed certificate generation stays as is.
        if ($rule->getAttribute('status') === 'created') {
            $rule->setAttribute('status', 'unverified');
        }

        $response->dynamic($rule, Response::MODEL_PROXY_RULE);
    }
}
