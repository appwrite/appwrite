<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Listings\Screenshots;

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
        return 'deleteListingScreenshot';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/distribution/apps/:appId/listings/:listingId/screenshots/:screenshotId')
            ->desc('Delete listing screenshot')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].listings.[listingId].update')
            ->label('audits.event', 'distribution.listing.update')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'listings',
                name: 'deleteListingScreenshot',
                description: <<<EOT
                Remove a screenshot from a store listing by its unique ID. Releases already submitted to production keep the listing they were snapshotted with, including this screenshot.
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
            ->param('screenshotId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Screenshot ID.', false, ['dbForProject'])
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
        string $screenshotId,
        Response $response,
        Database $dbForProject,
        Document $project,
        Event $queueForEvents,
        callable $locks,
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        try {
            $listing = $locks('distribution:listing:' . $project->getId() . ':' . $listingId, 60, function () use ($app, $dbForProject, $listingId, $screenshotId): Document {
                $listing = $dbForProject->getDocument('distributionListings', $listingId);

                if ($listing->isEmpty() || $listing->getAttribute('appInternalId') !== $app->getSequence()) {
                    throw new Exception(Exception::DISTRIBUTION_LISTING_NOT_FOUND);
                }

                $screenshots = $listing->getAttribute('screenshots', []);
                $remaining = \array_values(\array_filter(
                    $screenshots,
                    static fn (mixed $screenshot): bool => !($screenshot instanceof Document || \is_array($screenshot)) || ($screenshot['$id'] ?? null) !== $screenshotId,
                ));

                if (\count($remaining) === \count($screenshots)) {
                    throw new Exception(Exception::STORAGE_FILE_NOT_FOUND, 'The listing has no screenshot with this ID.');
                }

                // The file stays: release snapshots reference screenshots by path, and app deletion removes the listing directory.
                return $dbForProject->updateDocument('distributionListings', $listing->getId(), new Document([
                    'screenshots' => $remaining,
                    'revision' => (int) $listing->getAttribute('revision', 0) + 1,
                ]));
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
