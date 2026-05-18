<?php

namespace Appwrite\Platform\Modules\Presences\HTTP;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action as PlatformAction;
use Appwrite\Presences\State as PresenceState;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Presences as PresencesQueries;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Relationship as RelationshipException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Range;

class XList extends PlatformAction
{
    use HTTP;

    public static function getName()
    {
        return 'listPresences';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/presences')
            ->desc('List presences')
            ->groups(['api', 'presences'])
            ->label('scope', 'presences.read')
            ->label('sdk', new Method(
                namespace: 'presences',
                group: 'presences',
                name: 'list',
                desc: 'List presences',
                description: '/docs/references/presences/list.md',
                auth: [AuthType::ADMIN, AuthType::KEY, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PRESENCE_LIST,
                    ),
                ],
            ))
            ->param('queries', [], new PresencesQueries(), 'Array of query strings generated using the Query class provided by the SDK.', true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->param('ttl', 0, new Range(min: 0, max: 86400), 'TTL (seconds) for caching list responses. Responses are stored in an in-memory key-value cache, keyed per project, collection, schema version (attributes and indexes), caller authorization roles, and the exact query — so users with different permissions never share cached entries. Schema changes invalidate cached entries automatically; document writes do not, so choose a TTL you are comfortable serving as stale data. Set to 0 to disable caching. Must be between 0 and 86400 (24 hours).', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(array $queries, bool $includeTotal, int $ttl, Response $response, Database $dbForProject): void
    {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();

            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $presenceId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('presenceLogs', $presenceId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Presence '{$presenceId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $groupedQueries = Query::groupByType($queries);
        $filterQueries = $groupedQueries['filters'];

        // should be excluded from the user provided query as user query would be used for caching only
        // otherwise cache will always miss due to the datetime now
        $expiryFilter = Query::greaterThan('expiresAt', DateTime::now());

        try {
            if ((int)$ttl > 0) {
                $presenceState = new PresenceState();
                $roles = $dbForProject->getAuthorization()->getRoles();

                $documentsCacheHit = false;
                $cachedDocuments = $presenceState->getListCacheField(
                    $dbForProject,
                    $roles,
                    $queries,
                    PresenceState::LIST_CACHE_FIELD_PRESENCES,
                    $ttl
                );

                if ($cachedDocuments !== null &&
                    $cachedDocuments !== false &&
                    \is_array($cachedDocuments)) {
                    $documents = \array_map(function ($doc) {
                        return new Document($doc);
                    }, $cachedDocuments);
                    $documentsCacheHit = true;
                } else {
                    $documents = $dbForProject->find('presenceLogs', [...$queries, $expiryFilter]);
                    $documentsArray = \array_map(function ($doc) {
                        return $doc->getArrayCopy();
                    }, $documents);
                    $presenceState->setListCacheField(
                        $dbForProject,
                        $roles,
                        $queries,
                        PresenceState::LIST_CACHE_FIELD_PRESENCES,
                        $documentsArray
                    );
                }

                if ($includeTotal) {
                    $cachedTotal = $presenceState->getListCacheField(
                        $dbForProject,
                        $roles,
                        $filterQueries,
                        PresenceState::LIST_CACHE_FIELD_TOTAL,
                        $ttl
                    );
                    if ($cachedTotal !== null && $cachedTotal !== false) {
                        $total = (int) $cachedTotal;
                    } else {
                        $total = $dbForProject->count('presenceLogs', [...$filterQueries, $expiryFilter], APP_LIMIT_COUNT);
                        $presenceState->setListCacheField(
                            $dbForProject,
                            $roles,
                            $filterQueries,
                            PresenceState::LIST_CACHE_FIELD_TOTAL,
                            $total
                        );
                    }
                } else {
                    $total = 0;
                }

                $response->addHeader('X-Appwrite-Cache', $documentsCacheHit ? 'hit' : 'miss');
            } else {
                $documents = $dbForProject->find('presenceLogs', [...$queries, $expiryFilter]);
                $total = $includeTotal ? $dbForProject->count('presenceLogs', [...$filterQueries, $expiryFilter], APP_LIMIT_COUNT) : 0;
            }
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage(), previous: $e);
        } catch (RelationshipException $e) {
            throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, $e->getMessage(), previous: $e);
        }

        $response->dynamic(new Document([
            'presences' => $documents,
            'total' => $total,
        ]), Response::MODEL_PRESENCE_LIST);
    }
}
