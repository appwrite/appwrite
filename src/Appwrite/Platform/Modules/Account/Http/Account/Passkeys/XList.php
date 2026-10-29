<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Passkeys;

use Appwrite\Auth\Passkey\Ceremony;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Passkeys;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listPasskeys';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/account/passkeys')
            ->desc('List passkeys')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'passkeys',
                name: 'listPasskeys',
                description: <<<EOT
                Get the list of verified passkeys registered by the currently logged in user.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PASSKEY_LIST,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('queries', [], new Passkeys(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter and order on the following attributes: $id, $createdAt, $updatedAt, ' . implode(', ', Passkeys::ALLOWED_ATTRIBUTES), true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        array $queries,
        bool $includeTotal,
        Response $response,
        Document $user,
        Database $dbForProject,
    ): void {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $queries[] = Query::equal('userInternalId', [$user->getSequence()]);
        $queries[] = Query::equal('type', [Ceremony::TYPE]);
        $queries[] = Query::equal('verified', [true]);

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $passkeyId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('authenticators', $passkeyId);

            if (
                $cursorDocument->isEmpty()
                || $cursorDocument->getAttribute('type') !== Ceremony::TYPE
                || $cursorDocument->getAttribute('userInternalId') !== $user->getSequence()
            ) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Passkey '{$passkeyId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)->filters;
        try {
            $passkeys = $dbForProject->find('authenticators', $queries);
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }
        $total = $includeTotal ? $dbForProject->count('authenticators', $filterQueries, APP_LIMIT_COUNT) : 0;

        $response->dynamic(new Document([
            'passkeys' => $passkeys,
            'total' => $total,
        ]), Response::MODEL_PASSKEY_LIST);
    }
}
