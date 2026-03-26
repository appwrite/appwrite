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
        $requestingUserId = $user->getId();
        $hostname = $dbForProject->getAdapter()->getHostname();
        $usersCacheKey = \sprintf(
                        '%s-cache-%s:%s:%s:collection:presence',
                        $dbForProject->getCacheName(),
                        $hostname ?? '',
                        $dbForProject->getNamespace(),
                        $dbForProject->getTenant()
                    );
        $presenceLogsCacheKey = \sprintf(
                        '%s-cache-%s:%s:%s:collection:presenceLogs:user:%s',
                        $dbForProject->getCacheName(),
                        $hostname ?? '',
                        $dbForProject->getNamespace(),
                        $dbForProject->getTenant(),
                        $requestingUserId
                    );
        $ttl = 60;
        try {
            $presenceLogs = [];
            $totalStart = microtime(true);
            $userStart = microtime(true);

            $presenceCacheStart = microtime(true);
            $cachedPresenceLogs = $dbForProject->getCache()->load($presenceLogsCacheKey, $ttl);
            if($cachedPresenceLogs !== null && $cachedPresenceLogs !== false && \is_array($cachedPresenceLogs)){
                $presenceLogs = array_map(fn($doc) => new Document($doc), $cachedPresenceLogs);
                $presenceLogEnd = microtime(true) - $presenceCacheStart;
                $totalEnd = microtime(true) - $totalStart;
                Console::info(sprintf(
                    "Cache | Requesting User [%s] | [Total][time] %.2f ms | [Users][time] %.2f ms | [PresenceCache][time] %.2f ms\n",
                    $requestingUserId,
                    $totalEnd * 1000,
                    0,
                    $presenceLogEnd * 1000
                ));

                $response->dynamic(new Document([
                    'presences' => $presenceLogs,
                    'total' => \count($presenceLogs),
                ]), UtopiaResponse::MODEL_PRESENCE_LIST);

                return;
            }

            $cachedUsers = $dbForProject->getCache()->load($usersCacheKey, $ttl);
            if($cachedUsers !== null && $cachedUsers !== false && \is_array($cachedUsers)){
                $users = array_map(fn($doc) => new Document($doc), $cachedUsers);
            }
            else{
                $users = $authorization->skip(fn () => $dbForProject->find('presence', [
                    Query::limit(10000),
                ]));

                // saving to cache
                $documentsArray = \array_map(function ($doc) {
                            return $doc->getArrayCopy();
                        }, $users);
                $dbForProject->getCache()->save($usersCacheKey, $documentsArray);
            }

            $userEnd = microtime(true) - $userStart;
            $presenceLogStart = microtime(true);
            foreach ($users as $presenceUser) {
                // $presenceCacheKey = \sprintf(
                //         '%s-cache-%s:%s:%s:collection:presenceLogs:user:%s',
                //         $dbForProject->getCacheName(),
                //         $hostname ?? '',
                //         $dbForProject->getNamespace(),
                //         $dbForProject->getTenant(),
                //         $presenceUser['userId'] ?? ''
                //     );
                
                $presenceLog = $dbForProject->findOne('presenceLogs', [
                        Query::equal('userId', [$presenceUser['userId']]),
                        Query::orderDesc('$updatedAt'),
                        // Tie-breaker: `$updatedAt` has only millisecond precision.
                        Query::orderDesc('$id'),
                        Query::limit(1),
                    ]);
                
                $presenceLogs[] = $presenceLog;
            }
            $dbForProject->getCache()->save($presenceLogsCacheKey, $presenceLogs);
            $presenceLogEnd = microtime(true) - $presenceLogStart;
            $totalEnd = microtime(true) - $totalStart;
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order column '{$e->getAttribute()}' had a null value. Cursor pagination requires all rows order column values are non-null.");
        } catch (QueryException) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID);
        }
        Console::info(sprintf(
            "Requesting User [%s] | [Total][time] %.2f ms | [Users][time] %.2f ms | [PresenceLogs][time] %.2f ms\n",
            $requestingUserId,
            $totalEnd * 1000,
            $userEnd * 1000,
            $presenceLogEnd * 1000
        ));
        $response->dynamic(new Document([
            'presences' => $presenceLogs,
            'total' => \count($presenceLogs),
        ]), UtopiaResponse::MODEL_PRESENCE_LIST);
    }
}
