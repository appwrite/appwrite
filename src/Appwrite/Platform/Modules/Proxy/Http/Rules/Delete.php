<?php

namespace Appwrite\Platform\Modules\Proxy\Http\Rules;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'deleteRule';
    }

    public function __construct()
    {
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
            ->inject('publisherForDeletes')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $ruleId,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        DeletePublisher $publisherForDeletes,
        Event $queueForEvents,
        Authorization $authorization,
    ) {
        $rule = $authorization->skip(fn () => $dbForPlatform->getDocument('rules', $ruleId));

        if ($rule->isEmpty() || $rule->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::RULE_NOT_FOUND);
        }

        $authorization->skip(fn () => $dbForPlatform->deleteDocument('rules', $rule->getId()));

        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $project,
            type: DELETE_TYPE_DOCUMENT,
            document: $rule,
        ));

        $queueForEvents->setParam('ruleId', $rule->getId());

        $response->noContent();
    }
}
