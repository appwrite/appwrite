<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Listings;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\DistributionListings;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Boolean;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listListings';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/distribution/apps/:appId/listings')
            ->desc('List listings')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.read')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'listings',
                name: 'listListings',
                description: <<<EOT
                Get a list of all the store listings of an app. You can use the query params to filter your results.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DISTRIBUTION_LISTING_LIST,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->param('queries', [], new DistributionListings(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . \implode(', ', DistributionListings::ALLOWED_ATTRIBUTES), true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    /**
     * @param array<string> $queries
     */
    public function action(
        string $appId,
        array $queries,
        bool $includeTotal,
        Response $response,
        Database $dbForProject
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $error) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $error->getMessage());
        }

        $queries[] = Query::equal('appInternalId', [$app->getSequence()]);

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $listingId = $cursor->getValue();
            $cursorDocument = $dbForProject->findOne('distributionListings', [
                Query::equal('$id', [$listingId]),
                Query::equal('appInternalId', [$app->getSequence()]),
            ]);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Listing '{$listingId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)->filters;

        try {
            $listings = $dbForProject->find('distributionListings', $queries);
            $total = $includeTotal ? $dbForProject->count('distributionListings', $filterQueries, APP_LIMIT_COUNT) : 0;
        } catch (OrderException $error) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$error->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        } catch (QueryException $error) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $error->getMessage());
        }

        $response->dynamic(new Document([
            'listings' => $listings,
            'total' => $total,
        ]), Response::MODEL_DISTRIBUTION_LISTING_LIST);
    }
}
