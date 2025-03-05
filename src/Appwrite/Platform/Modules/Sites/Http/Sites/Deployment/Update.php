<?php

namespace Appwrite\Platform\Modules\Sites\Http\Sites\Deployment;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Query;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateSiteDeployment';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/sites/:siteId/deployment')
            ->desc('Update site\'s deployment')
            ->groups(['api', 'sites'])
            ->label('scope', 'sites.write')
            ->label('resourceType', RESOURCE_TYPE_SITES)
            ->label('event', 'sites.[siteId].deployments.[deploymentId].update')
            ->label('audits.event', 'deployment.update')
            ->label('audits.resource', 'site/{request.siteId}')
            ->label('sdk', new Method(
                namespace: 'sites',
                name: 'updateSiteDeployment',
                description: <<<EOT
                Update the site active deployment. Use this endpoint to switch the code deployment that should be used when visitor opens your site.
                EOT,
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_SITE,
                    )
                ]
            ))
            ->param('siteId', '', new UID(), 'Site ID.')
            ->param('deploymentId', '', new UID(), 'Deployment ID.')
            ->inject('project')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->callback([$this, 'action']);
    }

    public function action(string $siteId, string $deploymentId, Document $project, Response $response, Database $dbForProject, Event $queueForEvents, Database $dbForPlatform)
    {
        $site = $dbForProject->getDocument('sites', $siteId);
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);
        $build = $dbForProject->getDocument('builds', $deployment->getAttribute('buildId', ''));

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        if ($build->isEmpty()) {
            throw new Exception(Exception::BUILD_NOT_FOUND);
        }

        if ($build->getAttribute('status') !== 'ready') {
            throw new Exception(Exception::BUILD_NOT_READY);
        }

        $site = $dbForProject->updateDocument('sites', $site->getId(), new Document(array_merge($site->getArrayCopy(), [
            'deploymentInternalId' => $deployment->getInternalId(),
            'deploymentId' => $deployment->getId(),
        ])));

        $this->listRules($project, [
            Query::equal("automation", ["site=" . $site->getId()]),
        ], $dbForPlatform, function (Document $rule) use ($dbForPlatform, $deployment) {
            $rule = $rule->setAttribute('value', $deployment->getId());
            $dbForPlatform->updateDocument('rules', $rule->getId(), $rule);
        });

        $queueForEvents
            ->setParam('siteId', $site->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response->dynamic($site, Response::MODEL_SITE);
    }

    protected function listRules(Document $project, array $queries, Database $database, callable $callback): void
    {
        $limit = 100;
        $cursor = null;

        do {
            $queries = \array_merge([
                Query::limit($limit),
                Query::equal("projectInternalId", [$project->getInternalId()])
            ], $queries);

            if ($cursor !== null) {
                $queries[] = Query::cursorAfter($cursor);
            }

            $results = $database->find('rules', $queries);

            $total = \count($results);
            if ($total > 0) {
                $cursor = $results[$total - 1];
            }

            if ($total < $limit) {
                $cursor = null;
            }

            foreach ($results as $document) {
                if (is_callable($callback)) {
                    $callback($document);
                }
            }
        } while (!\is_null($cursor));
    }
}
