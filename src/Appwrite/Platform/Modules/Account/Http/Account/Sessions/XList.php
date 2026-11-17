<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Validator\Queries\Sessions;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listSessions';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/account/sessions')
            ->desc('List sessions')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'sessions',
                name: 'listSessions',
                description: '/docs/references/account/list-sessions.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_SESSION_LIST,
                    )
                ],
                contentType: ContentType::JSON,
            ))
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->param('queries', [], new Sessions(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter and order on the following attributes: $id, $createdAt, $updatedAt, ' . implode(', ', Sessions::ALLOWED_ATTRIBUTES), true)
            ->inject('response')
            ->inject('targetUser')
            ->inject('locale')
            ->inject('session')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        bool $includeTotal,
        array $queries,
        Response $response,
        User $targetUser,
        Locale $locale,
        ?Document $current,
        Database $dbForProject,
    ): void {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $queries[] = Query::equal('userInternalId', [$targetUser->getSequence()]);

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $sessionId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('sessions', $sessionId);

            if (
                $cursorDocument->isEmpty()
                || $cursorDocument->getAttribute('userInternalId') !== $targetUser->getSequence()
            ) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Session '{$sessionId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $grouped = Query::groupByType($queries);
        $filterQueries = $grouped->filters;

        // A caller that filters on expire owns the time window; otherwise list only
        // active sessions, as the endpoint documents.
        $hasExpireFilter = false;
        foreach ($filterQueries as $query) {
            if ($query->getAttribute() === 'expire') {
                $hasExpireFilter = true;
                break;
            }
        }

        if (!$hasExpireFilter) {
            $expire = Query::greaterThanEqual('expire', DateTime::now());
            $queries[] = $expire;
            $filterQueries[] = $expire;
        }

        // Today's endpoint returns every session up to the subquery cap. Keep that
        // default for callers that do not paginate, while queries and total can now
        // go beyond it.
        if ($grouped->limit === null) {
            $queries[] = Query::limit(APP_LIMIT_SUBQUERY);
        }

        try {
            $sessions = $dbForProject->find('sessions', $queries);
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }
        $total = $includeTotal ? $dbForProject->count('sessions', $filterQueries, APP_LIMIT_COUNT) : 0;

        // While impersonating, the request runs on the impersonator's session, so none of
        // the target's sessions is marked current.
        $currentId = $current?->getId();

        foreach ($sessions as $session) {
            /** @var Document $session */
            $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

            $session->setAttribute('countryName', $countryName);
            $session->setAttribute('current', $currentId === $session->getId());
            $session->setAttribute('userName', $targetUser->getAttribute('name', ''));
            $session->setAttribute('secret', $session->getAttribute('secret', ''));
        }

        $response->dynamic(new Document([
            'sessions' => $sessions,
            'total' => $total,
        ]), Response::MODEL_SESSION_LIST);
    }
}
