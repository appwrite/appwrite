<?php

namespace Appwrite\Platform\Modules\Proxy\Http\Rules\Function;

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
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateFunctionRule';
    }

    public function __construct(...$params)
    {
        parent::__construct(...$params);

        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/proxy/rules/:ruleId/function')
            ->groups(['api', 'proxy'])
            ->desc('Update function rule')
            ->label('scope', 'rules.write')
            ->label('event', 'rules.[ruleId].update')
            ->label('audits.event', 'rule.update')
            ->label('audits.resource', 'rule/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'proxy',
                group: 'rules',
                name: 'updateFunctionRule',
                description: <<<EOT
                Update the function and VCS branch a function proxy rule executes. The rule domain cannot be changed.
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
            ->param('functionId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'ID of function to be executed.', false, ['dbForProject'])
            ->param('branch', '', new Text(255, 0), 'Name of VCS branch to deploy changes automatically', true)
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
        string $functionId,
        string $branch,
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
            || $rule->getAttribute('type') !== 'deployment'
            || $rule->getAttribute('deploymentResourceType') !== 'function'
        ) {
            throw new Exception(Exception::RULE_NOT_FOUND);
        }

        $function = $dbForProject->getDocument('functions', $functionId);
        if ($function->isEmpty()) {
            throw new Exception(Exception::RULE_RESOURCE_NOT_FOUND);
        }

        // Same deployment pick as create: a branch-pinned rule serves that branch's newest build.
        $deployment = $branch === ''
            ? $dbForProject->getDocument('deployments', $function->getAttribute('deploymentId', ''))
            : $dbForProject->findOne('deployments', [
                Query::equal('resourceType', ['functions']),
                Query::equal('resourceInternalId', [$function->getSequence()]),
                Query::equal('providerBranch', [$branch]),
                Query::equal('status', ['ready']),
                Query::isNotNull('installationId'),
                Query::notEqual('installationId', ''),
                Query::orderDesc('$createdAt'),
                Query::orderDesc('$sequence'),
            ]);

        // Unlike create, the rule may already be serving: never swap it onto a branch with nothing to serve.
        if ($branch !== '' && $deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND, 'No ready deployment found for branch: ' . $branch);
        }

        $rule = $authorization->skip(fn () => $dbForPlatform->updateDocument('rules', $rule->getId(), new Document([
            'deploymentId' => $deployment->isEmpty() ? '' : $deployment->getId(),
            'deploymentInternalId' => $deployment->isEmpty() ? '' : $deployment->getSequence(),
            'deploymentResourceId' => $function->getId(),
            'deploymentResourceInternalId' => $function->getSequence(),
            'deploymentVcsProviderBranch' => $branch,
            'search' => implode(' ', [$rule->getId(), $rule->getAttribute('domain'), $branch]),
        ])));
        $bus->dispatch(new RuleUpdated($rule->getArrayCopy()));

        $queueForEvents->setParam('ruleId', $rule->getId());

        if ($rule->getAttribute('status') === 'created') {
            $rule->setAttribute('status', 'unverified');
        }

        $response->dynamic($rule, Response::MODEL_PROXY_RULE);
    }
}
