<?php

namespace Appwrite\Platform\Modules\Presence\HTTP\Iterative;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Platform\Action;

class XList extends Action
{
    public static function getName(): string
    {
        return 'listPresence';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            // TODO: create a separate scope
            ->groups(['api', 'presence'])
            ->label('scope', 'documents.read')
            ->setHttpPath('/v1/iterative/presence')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('user')
            ->callback($this->action(...));
    }
    // Since POC so not adding queries or advanced filtering now
    // just for getting group based presence list based on permissions
    public function action(UtopiaResponse $response, Database $dbForProject, Authorization $authorization, Document $user): void
    {
        try {
            $presenceLogs = [];

            $users = $authorization->skip(fn () => $dbForProject->find('presence', [
                Query::limit(10000),
            ]));
            foreach ($users as $user) {
                $presenceLog = $dbForProject->findOne('presenceLogs', [
                    Query::equal('userId', [$user['userId']]),
                    Query::orderDesc('$updatedAt'),
                    // Tie-breaker: `$updatedAt` has only millisecond precision.
                    Query::orderDesc('$id'),
                    Query::limit(1),
                ]);
                $presenceLogs[] = $presenceLog;
            }
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order column '{$e->getAttribute()}' had a null value. Cursor pagination requires all rows order column values are non-null.");
        } catch (QueryException) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID);
        }

        $response->dynamic(new Document([
            'presences' => $presenceLogs,
            'total' => \count($presenceLogs),
        ]), UtopiaResponse::MODEL_PRESENCE_LIST);
    }
}
