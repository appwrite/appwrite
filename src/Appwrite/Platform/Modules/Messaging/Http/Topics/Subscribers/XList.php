<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Topics\Subscribers;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Subscribers;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

use function Swoole\Coroutine\batch;

class XList extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'listSubscribers';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/messaging/topics/:topicId/subscribers')
            ->desc('List subscribers')
            ->groups(['api', 'messaging'])
            ->label('scope', 'subscribers.read')
            ->label('resourceType', RESOURCE_TYPE_SUBSCRIBERS)
            ->label('sdk', new Method(
                namespace: 'messaging',
                group: 'subscribers',
                name: 'listSubscribers',
                description: '/docs/references/messaging/list-subscribers.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_SUBSCRIBER_LIST,
                    )
                ]
            ))
            ->param('topicId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Topic ID. The topic ID subscribed to.', false, ['dbForProject'])
            ->param('queries', [], new Subscribers(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Subscribers::ALLOWED_ATTRIBUTES), true)
            ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $topicId, array $queries, string $search, bool $includeTotal, Database $dbForProject, Authorization $authorization, Response $response)
    {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        $topic = $authorization->skip(fn () => $dbForProject->getDocument('topics', $topicId));

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        $queries[] = Query::equal('topicInternalId', [$topic->getSequence()]);

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $subscriberId = $cursor->getValue();
            $cursorDocument = $authorization->skip(fn () => $dbForProject->getDocument('subscribers', $subscriberId));

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Subscriber '{$subscriberId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }
        try {
            $subscribers = $dbForProject->find('subscribers', $queries);
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }

        $subscribers = batch(\array_map(function (Document $subscriber) use ($dbForProject, $authorization) {
            return function () use ($subscriber, $dbForProject, $authorization) {
                $target = $authorization->skip(fn () => $dbForProject->getDocument('targets', $subscriber->getAttribute('targetId')));
                $user = $authorization->skip(fn () => $dbForProject->getDocument('users', $target->getAttribute('userId')));

                return $subscriber
                    ->setAttribute('target', $target)
                    ->setAttribute('userName', $user->getAttribute('name'));
            };
        }, $subscribers));

        $response
            ->dynamic(new Document([
                'subscribers' => $subscribers,
                'total' => $includeTotal ? $dbForProject->count('subscribers', $queries, APP_LIMIT_COUNT) : 0,
            ]), Response::MODEL_SUBSCRIBER_LIST);
    }
}
