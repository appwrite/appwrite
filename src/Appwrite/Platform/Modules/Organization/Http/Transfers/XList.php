<?php

namespace Appwrite\Platform\Modules\Organization\Http\Transfers;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Transfers;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'listProjectTransfers';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/organization/transfers')
            ->desc('List project transfers')
            ->groups(['api', 'organization'])
            ->label('scope', 'projects.read')
            ->label('sdk', new Method(
                namespace: 'organization',
                group: 'transfers',
                name: 'listProjectTransfers',
                description: <<<EOT
                Get a list of the pending transfers of projects in your organization, with their secrets so their links can be shared again.
                EOT,
                auth: [AuthType::ADMIN, AuthType::ORGANIZATION],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_TRANSFER_LIST,
                    )
                ]
            ))
            ->param('projectId', '', new UID(), 'Only list the transfers of this project.', true)
            ->param('queries', [], new Transfers(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Transfers::ALLOWED_ATTRIBUTES), true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('team')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * @param array<string> $queries
     */
    public function action(
        string $projectId,
        array $queries,
        bool $includeTotal,
        Response $response,
        Database $dbForPlatform,
        Document $team,
        Authorization $authorization,
    ) {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $queries[] = Query::equal('resourceType', ['projects']);
        $queries[] = Query::equal('senderType', ['teams']);
        $queries[] = Query::equal('senderInternalId', [$team->getSequence()]);
        if ($projectId !== '') {
            $queries[] = Query::equal('resourceId', [$projectId]);
        }

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $transferId = $cursor->getValue();
            $cursorDocument = $authorization->skip(fn () => $dbForPlatform->findOne('transfers', [
                Query::equal('$id', [$transferId]),
                Query::equal('resourceType', ['projects']),
                Query::equal('senderType', ['teams']),
                Query::equal('senderInternalId', [$team->getSequence()]),
            ]));

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Transfer '{$transferId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)->filters;

        try {
            $transfers = $authorization->skip(fn () => $dbForPlatform->find('transfers', $queries));
            $total = $includeTotal ? $authorization->skip(fn () => $dbForPlatform->count('transfers', $filterQueries, APP_LIMIT_COUNT)) : 0;
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }

        $projectSequences = \array_values(\array_unique(\array_map(fn (Document $transfer) => $transfer->getAttribute('resourceInternalId'), $transfers)));
        $projects = [];
        if ($projectSequences !== []) {
            $found = $authorization->skip(fn () => $dbForPlatform->find('projects', [
                Query::equal('$sequence', $projectSequences),
                Query::limit(\count($projectSequences)),
            ]));
            foreach ($found as $project) {
                $projects[$project->getSequence()] = $project;
            }
        }

        $organizations = $this->getOrganizations($transfers, $dbForPlatform, $authorization);
        foreach ($transfers as $transfer) {
            $this->present($transfer, $projects[$transfer->getAttribute('resourceInternalId')] ?? new Document(), $organizations);
        }

        $response->dynamic(new Document([
            'transfers' => $transfers,
            'total' => $total,
        ]), Response::MODEL_TRANSFER_LIST);
    }
}
