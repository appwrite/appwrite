<?php

namespace Appwrite\Platform\Modules\Sites\Http\Sites\Deployment;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Update extends Base
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
                group: 'sites',
                name: 'updateSiteDeployment',
                description: <<<EOT
                Update the site active deployment. Use this endpoint to switch the code deployment that should be used when visitor opens your site.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
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
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $siteId,
        string $deploymentId,
        Document $project,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        Database $dbForPlatform,
        Authorization $authorization
    ) {
        $site = $dbForProject->getDocument('sites', $siteId);
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        if ($deployment->getAttribute('status') !== 'ready') {
            throw new Exception(Exception::BUILD_NOT_READY);
        }

        $site = $dbForProject->updateDocument('sites', $site->getId(), new Document(array_merge($site->getArrayCopy(), [
            'deploymentInternalId' => $deployment->getSequence(),
            'deploymentId' => $deployment->getId(),
            'deploymentScreenshotDark' => $deployment->getAttribute('screenshotDark', ''),
            'deploymentScreenshotLight' => $deployment->getAttribute('screenshotLight', ''),
            'deploymentCreatedAt' => $deployment->getCreatedAt(),
        ])));

        $queries = [
            Query::equal('trigger', ['manual']),
            Query::equal('type', ['deployment']),
            Query::equal('deploymentResourceType', ['site']),
            Query::equal('deploymentResourceInternalId', [$site->getSequence()]),
            Query::equal('deploymentVcsProviderBranch', ['']),
            Query::equal('projectInternalId', [$project->getSequence()])
        ];

        $authorization->skip(fn () => $dbForPlatform->foreach('rules', function (Document $rule) use ($dbForPlatform, $deployment, $authorization) {
            $rule = $rule
                ->setAttribute('deploymentId', $deployment->getId())
                ->setAttribute('deploymentInternalId', $deployment->getSequence());

            $authorization->skip(fn () => $dbForPlatform->updateDocument('rules', $rule->getId(), $rule));
        }, $queries));

        $queueForEvents
            ->setParam('siteId', $site->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response->dynamic($site, Response::MODEL_SITE);
    }
}
