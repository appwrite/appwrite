<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Listings;

use Appwrite\Distribution\Listings;
use Appwrite\Distribution\Platform;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Lock\Exception\Contention;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\JSON;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateListing';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/distribution/apps/:appId/listings/:listingId')
            ->desc('Update listing')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].listings.[listingId].update')
            ->label('audits.event', 'distribution.listing.update')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'listings',
                name: 'updateListing',
                description: <<<EOT
                Update the text of a store listing by its unique ID. The fields you pass replace all current fields, so fields you leave out are removed from the listing. A sync writes only what the listing holds: the store keeps text it already has for removed fields, and a screenshot set emptied here or a deleted listing is not removed from the store. The platform and locale of a listing cannot change. Releases already submitted to production keep the listing they snapshotted.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DISTRIBUTION_LISTING,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->param('listingId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Listing ID.', false, ['dbForProject'])
            ->param('fields', [], new JSON(), 'Listing text as an object of field to text. Replaces the current fields; omitted fields are removed from the listing. The store keeps text it already has for a field the listing no longer holds. Android accepts ' . \implode(', ', Listings::FIELDS[Platform::Android->value]) . '; iOS accepts ' . \implode(', ', Listings::FIELDS[Platform::Ios->value]) . '.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('locks')
            ->callback($this->action(...));
    }

    /**
     * @param array<array-key, mixed>|string $fields
     */
    public function action(
        string $appId,
        string $listingId,
        array|string $fields,
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
            $listing = $locks('distribution:listing:' . $project->getId() . ':' . $listingId, 60, function () use ($app, $dbForProject, $fields, $listingId): Document {
                $listing = $dbForProject->getDocument('distributionListings', $listingId);

                if ($listing->isEmpty() || $listing->getAttribute('appInternalId') !== $app->getSequence()) {
                    throw new Exception(Exception::DISTRIBUTION_LISTING_NOT_FOUND);
                }

                return $dbForProject->updateDocument('distributionListings', $listing->getId(), new Document([
                    'fields' => Listings::fields(Platform::from((string) $listing->getAttribute('platform')), $fields),
                    'revision' => (int) $listing->getAttribute('revision', 0) + 1,
                ]));
            }, timeout: 10.0);
        } catch (Contention) {
            $response->addHeader('Retry-After', '5');
            throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Listing is busy. Try again.');
        }

        $queueForEvents
            ->setParam('appId', $app->getId())
            ->setParam('listingId', $listing->getId());

        $response->dynamic($listing, Response::MODEL_DISTRIBUTION_LISTING);
    }
}
