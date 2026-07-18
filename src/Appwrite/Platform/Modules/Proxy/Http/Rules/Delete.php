<?php

namespace Appwrite\Platform\Modules\Proxy\Http\Rules;

use Appwrite\Event\Delete as DeleteEvent;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Proxy\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Usage\Context as UsageContext;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'deleteRule';
    }

    public function __construct(...$params)
    {
        parent::__construct(...$params);

        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/proxy/rules/:ruleId')
            ->desc('Delete rule')
            ->groups(['api', 'proxy'])
            ->label('scope', 'rules.write')
            ->label('event', 'rules.[ruleId].delete')
            ->label('audits.event', 'rules.delete')
            ->label('audits.resource', 'rule/{request.ruleId}')
            ->label('sdk', new Method(
                namespace: 'proxy',
                group: 'rules',
                name: 'deleteRule',
                description: <<<EOT
                Delete a proxy rule by its unique ID.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('ruleId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Rule ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('queueForDeletes')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->inject('usage')
            ->callback($this->action(...));
    }

    public function action(
        string $ruleId,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        DeleteEvent $queueForDeletes,
        Event $queueForEvents,
        Authorization $authorization,
        UsageContext $usage,
    ) {
        $rule = $authorization->skip(fn () => $dbForPlatform->getDocument('rules', $ruleId));

        if ($rule->isEmpty() || $rule->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::RULE_NOT_FOUND);
        }

        $authorization->skip(fn () => $dbForPlatform->deleteDocument('rules', $rule->getId()));

        $this->adjustDomainUsage($usage, $rule, -1);

        $queueForDeletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($rule);

        $queueForEvents->setParam('ruleId', $rule->getId());

        $response->noContent();
    }
}
