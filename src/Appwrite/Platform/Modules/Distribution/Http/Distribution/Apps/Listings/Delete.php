<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Listings;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Lock\Exception\Contention;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteListing';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/distribution/apps/:appId/listings/:listingId')
            ->desc('Delete listing')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].listings.[listingId].delete')
            ->label('audits.event', 'distribution.listing.delete')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'listings',
                name: 'deleteListing',
                description: <<<EOT
                Delete a store listing of an app by its unique ID. Releases already submitted to production keep the listing they snapshotted, including its screenshots.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE,
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->param('listingId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Listing ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('locks')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        string $listingId,
        Response $response,
        Database $dbForProject,
        Document $project,
        Event $queueForEvents,
        callable $locks
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        try {
            $listing = $locks('distribution:listing:' . $project->getId() . ':' . $listingId, 60, function () use ($app, $dbForProject, $listingId): Document {
                $listing = $dbForProject->getDocument('distributionListings', $listingId);

                if ($listing->isEmpty() || $listing->getAttribute('appInternalId') !== $app->getSequence()) {
                    throw new Exception(Exception::DISTRIBUTION_LISTING_NOT_FOUND);
                }

                if (!$dbForProject->deleteDocument('distributionListings', $listing->getId())) {
                    throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove listing from DB');
                }

                return $listing;
            }, timeout: 10.0);
        } catch (Contention) {
            $response->addHeader('Retry-After', '5');
            throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Listing is busy. Try again.');
        }

        $queueForEvents
            ->setParam('appId', $app->getId())
            ->setParam('listingId', $listing->getId())
            ->setPayload($response->output($listing, Response::MODEL_DISTRIBUTION_LISTING));

        $response->noContent();
    }
}
