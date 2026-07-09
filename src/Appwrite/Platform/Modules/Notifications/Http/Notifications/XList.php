<?php

namespace Appwrite\Platform\Modules\Notifications\Http\Notifications;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Notifications as NotificationQueries;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listNotifications';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/notifications')
            ->desc('List notifications')
            ->groups(['api', 'notifications'])
            ->label('scope', 'account')
            ->label('sdk', new Method(
                namespace: 'notifications',
                group: null,
                name: 'list',
                description: '/docs/references/notifications/list-notifications.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NOTIFICATION_LIST,
                    )
                ]
            ))
            ->param('queries', [], new NotificationQueries(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', NotificationQueries::ALLOWED_ATTRIBUTES), true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('user')
            ->callback($this->action(...));
    }

    /**
     * @param array<string> $queries
     */
    public function action(
        array $queries,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Document $user
    ): void {
        if ($project->getId() !== 'console') {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */

            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $notificationId = $cursor->getValue();
            $cursorDocument = $dbForPlatform->getDocument('notifications', $notificationId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Notification '{$notificationId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $queries[] = Query::equal('channel', [NOTIFICATION_TYPE_CONSOLE]);
        $queries[] = Query::equal('resourceType', [RESOURCE_TYPE_USERS]);
        $queries[] = Query::equal('resourceId', [$user->getId()]);

        $filterQueries = Query::groupByType($queries)['filters'];

        try {
            $results = $dbForPlatform->find('notifications', $queries);
            $total = $dbForPlatform->count('notifications', $filterQueries, APP_LIMIT_COUNT);
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }

        $response->dynamic(new Document([
            'notifications' => $results,
            'total' => $total,
        ]), Response::MODEL_NOTIFICATION_LIST);
    }
}
