<?php

namespace Appwrite\Platform\Modules\Sites\Http\Sites;

use Appwrite\Bus\Events\SiteDeleted;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Bus\Bus;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Delete extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'deleteSite';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/sites/:siteId')
            ->desc('Delete site')
            ->groups(['api', 'sites'])
            ->label('scope', 'sites.write')
            ->label('resourceType', RESOURCE_TYPE_SITES)
            ->label('audits.event', 'site.delete')
            ->label('audits.resource', 'site/{request.siteId}')
            ->label('usage.resource', 'site/{request.siteId}')
            ->label('sdk', new Method(
                namespace: 'sites',
                group: 'sites',
                name: 'delete',
                description: <<<EOT
                Delete a site by its unique ID.
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
            ->param('siteId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Site ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('publisherForDeletes')
            ->inject('bus')
            ->inject('project')
            ->inject('user')
            ->callback($this->action(...));
    }

    public function action(
        string $siteId,
        Response $response,
        Database $dbForProject,
        DeletePublisher $publisherForDeletes,
        Bus $bus,
        Document $project,
        Document $actor
    ) {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('sites', $site->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove site from DB');
        }

        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $project,
            type: DELETE_TYPE_DOCUMENT,
            document: $site,
        ));

        $bus->dispatch(new SiteDeleted($site, $project, $actor));

        $response->noContent();
    }
}
