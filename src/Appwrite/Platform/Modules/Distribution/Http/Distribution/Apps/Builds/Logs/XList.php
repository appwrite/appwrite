<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Builds\Logs;

use Appwrite\Distribution\Build\Stage;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\DistributionLogs;
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
        return 'listBuildLogs';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/distribution/apps/:appId/builds/:buildId/logs')
            ->desc('List build logs')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.read')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'builds',
                name: 'listBuildLogs',
                description: <<<EOT
                Get the log chunks of a build. Chunks are ordered by sequence and then by chunk index unless the queries set an order, so joining their content in order gives the full log. Secret variable values are masked.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DISTRIBUTION_LOG_LIST,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->param('buildId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Build ID.', false, ['dbForProject'])
            ->param('queries', [], new DistributionLogs(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . \implode(', ', DistributionLogs::ALLOWED_ATTRIBUTES) . '. A stage is one of: ' . \implode(', ', Stage::values()) . '.', true)
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
        string $buildId,
        array $queries,
        bool $includeTotal,
        Response $response,
        Database $dbForProject
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        $build = $dbForProject->getDocument('distributionBuilds', $buildId);

        if ($build->isEmpty() || $build->getAttribute('appInternalId') !== $app->getSequence()) {
            throw new Exception(Exception::DISTRIBUTION_BUILD_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $error) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $error->getMessage());
        }

        $queries[] = Query::equal('buildInternalId', [(string) $build->getSequence()]);

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $logId = $cursor->getValue();
            $cursorDocument = $dbForProject->findOne('distributionLogs', [
                Query::equal('$id', [$logId]),
                Query::equal('buildInternalId', [(string) $build->getSequence()]),
            ]);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Log '{$logId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $grouped = Query::groupByType($queries);

        if ($grouped->orderAttributes === []) {
            $queries[] = Query::orderAsc('sequence');
            $queries[] = Query::orderAsc('chunk');
        }

        try {
            $logs = $dbForProject->find('distributionLogs', $queries);
            $total = $includeTotal ? $dbForProject->count('distributionLogs', $grouped->filters, APP_LIMIT_COUNT) : 0;
        } catch (OrderException $error) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$error->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        } catch (QueryException $error) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $error->getMessage());
        }

        $response->dynamic(new Document([
            'logs' => $logs,
            'total' => $total,
        ]), Response::MODEL_DISTRIBUTION_LOG_LIST);
    }
}
