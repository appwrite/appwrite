<?php

namespace Appwrite\Platform\Modules\Proxy\Http\Rules\Redirect;

use Appwrite\Bus\Events\RuleUpdated;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Proxy\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Bus\Bus;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateRedirectRule';
    }

    public function __construct(...$params)
    {
        parent::__construct(...$params);

        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/proxy/rules/:ruleId/redirect')
            ->groups(['api', 'proxy'])
            ->desc('Update redirect rule')
            ->label('scope', 'rules.write')
            ->label('event', 'rules.[ruleId].update')
            ->label('audits.event', 'rule.update')
            ->label('audits.resource', 'rule/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'proxy',
                group: 'rules',
                name: 'updateRedirectRule',
                description: <<<EOT
                Update the target URL, status code and parent resource of a redirect proxy rule. The rule domain cannot be changed.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROXY_RULE,
                    )
                ]
            ))
            ->param('ruleId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Rule ID.', false, ['dbForProject'])
            ->param('url', null, new URL(), 'Target URL of redirection')
            ->param('statusCode', null, new WhiteList([301, 302, 307, 308]), 'Status code of redirection', enum: new Enum(
                name: 'StatusCode',
                map: [
                    '301' => 'MovedPermanently',
                    '302' => 'Found',
                    '307' => 'TemporaryRedirect',
                    '308' => 'PermanentRedirect',
                ]
            ))
            ->param('resourceId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'ID of parent resource.', false, ['dbForProject'])
            ->param('resourceType', '', new WhiteList(['site', 'function']), 'Type of parent resource.', enum: new Enum(
                name: 'ProxyResourceType',
                map: [
                    'site' => 'Site',
                    'function' => 'Function',
                ]
            ))
            ->inject('response')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('bus')
            ->callback($this->action(...));
    }

    public function action(
        string $ruleId,
        string $url,
        int $statusCode,
        string $resourceId,
        string $resourceType,
        Response $response,
        Document $project,
        Event $queueForEvents,
        Database $dbForPlatform,
        Database $dbForProject,
        Authorization $authorization,
        Bus $bus,
    ) {
        $rule = $authorization->skip(fn () => $dbForPlatform->getDocument('rules', $ruleId));

        if (
            $rule->isEmpty()
            || $rule->getAttribute('projectInternalId') !== $project->getSequence()
            || $rule->getAttribute('type') !== 'redirect'
        ) {
            throw new Exception(Exception::RULE_NOT_FOUND);
        }

        $collection = match ($resourceType) {
            'site' => 'sites',
            'function' => 'functions',
            default => throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid resource type: ' . $resourceType),
        };
        $resource = $dbForProject->getDocument($collection, $resourceId);
        if ($resource->isEmpty()) {
            throw new Exception(Exception::RULE_RESOURCE_NOT_FOUND);
        }

        $rule = $authorization->skip(fn () => $dbForPlatform->updateDocument('rules', $rule->getId(), new Document([
            'redirectUrl' => $url,
            'redirectStatusCode' => $statusCode,
            'deploymentResourceType' => $resourceType,
            'deploymentResourceId' => $resource->getId(),
            'deploymentResourceInternalId' => $resource->getSequence(),
        ])));
        $bus->dispatch(new RuleUpdated($rule->getArrayCopy()));

        $queueForEvents->setParam('ruleId', $rule->getId());

        if ($rule->getAttribute('status') === 'created') {
            $rule->setAttribute('status', 'unverified');
        }

        $response->dynamic($rule, Response::MODEL_PROXY_RULE);
    }
}
