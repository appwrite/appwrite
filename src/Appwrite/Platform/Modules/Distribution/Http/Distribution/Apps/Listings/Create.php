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
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Id;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\JSON;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createListing';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/distribution/apps/:appId/listings')
            ->desc('Create listing')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].listings.[listingId].create')
            ->label('audits.event', 'distribution.listing.create')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'listings',
                name: 'createListing',
                description: <<<EOT
                Create a new store listing for an app. A listing is the store page text of one platform in one locale, with its screenshots uploaded separately. An app has at most one listing per platform and locale. A release snapshots the listings of its platform at its first production submission and syncs that snapshot to the store at its release step.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_DISTRIBUTION_LISTING,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->param('listingId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getMaxUidLength()), 'Listing ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('platform', '', new WhiteList(Platform::values(), true), 'Platform of the store listing.', enum: new Enum(name: 'DistributionPlatform'))
            ->param('locale', '', new Text(16, 1), 'Store locale of the listing, for example en-US. Max length: 16 chars.')
            ->param('fields', [], new JSON(), 'Listing text as an object of field to text. Omitted fields are left out of the listing. The store keeps text it already has for a field the listing no longer holds. Android accepts ' . \implode(', ', Listings::FIELDS[Platform::Android->value]) . '; iOS accepts ' . \implode(', ', Listings::FIELDS[Platform::Ios->value]) . '.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    /**
     * @param array<array-key, mixed>|string $fields
     */
    public function action(
        string $appId,
        string $listingId,
        string $platform,
        string $locale,
        array|string $fields,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        $fields = Listings::fields(Platform::from($platform), $fields);
        $listingId = $listingId === 'unique()' ? Id::unique() : $listingId;

        try {
            $listing = $dbForProject->createDocument('distributionListings', new Document([
                '$id' => $listingId,
                'appId' => $app->getId(),
                'appInternalId' => $app->getSequence(),
                'platform' => $platform,
                'locale' => $locale,
                'fields' => $fields,
                'screenshots' => [],
                'revision' => 1,
            ]));
        } catch (Duplicate) {
            throw new Exception(Exception::RESOURCE_ALREADY_EXISTS, 'A listing with this ID, or for this platform and locale, already exists.');
        }

        $queueForEvents
            ->setParam('appId', $app->getId())
            ->setParam('listingId', $listing->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($listing, Response::MODEL_DISTRIBUTION_LISTING);
    }
}
