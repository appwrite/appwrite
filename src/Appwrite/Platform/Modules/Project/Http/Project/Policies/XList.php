<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Policies;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'listPolicies';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/project/policies')
            ->desc('List project policies')
            ->groups(['api', 'project'])
            ->label('scope', ['policies.read', 'project.policies.read'])
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'policies',
                name: 'listPolicies',
                description: <<<EOT
                Get a list of all project policies and their current configuration.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_POLICY_LIST,
                    )
                ]
            ))
            ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('project')
            ->callback($this->action(...));
    }

    /**
     * @param array<string> $queries
     */
    public function action(
        array $queries,
        bool $includeTotal,
        Response $response,
        Document $project,
    ) {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $auths = $project->getAttribute('auths', []);

        $policies = [
            new Document([
                '$id' => 'password-dictionary',
                'enabled' => $auths['passwordDictionary'] ?? false,
            ]),
            new Document([
                '$id' => 'password-history',
                'total' => $auths['passwordHistory'] ?? 0,
            ]),
            new Document([
                '$id' => 'password-personal-data',
                'enabled' => $auths['personalDataCheck'] ?? false,
            ]),
            new Document([
                '$id' => 'session-alert',
                'enabled' => $auths['sessionAlerts'] ?? false,
            ]),
            new Document([
                '$id' => 'session-duration',
                'duration' => $auths['duration'] ?? TOKEN_EXPIRATION_LOGIN_LONG,
            ]),
            new Document([
                '$id' => 'session-invalidation',
                'enabled' => $auths['invalidateSessions'] ?? true,
            ]),
            new Document([
                '$id' => 'session-limit',
                'total' => $auths['maxSessions'] ?? 0,
            ]),
            new Document([
                '$id' => 'user-limit',
                'total' => $auths['limit'] ?? 0,
            ]),
            new Document([
                '$id' => 'membership-privacy',
                'userId' => $auths['membershipsUserId'] ?? false,
                'userEmail' => $auths['membershipsUserEmail'] ?? false,
                'userPhone' => $auths['membershipsUserPhone'] ?? false,
                'userName' => $auths['membershipsUserName'] ?? false,
                'userMFA' => $auths['membershipsMfa'] ?? false,
            ]),
        ];

        $total = $includeTotal ? \count($policies) : 0;

        $grouped = Query::groupByType($queries);
        $offset = $grouped['offset'] ?? 0;
        $limit = $grouped['limit'] ?? null;

        $policies = \array_slice($policies, $offset, $limit);

        $response->dynamic(new Document([
            'policies' => $policies,
            'total' => $total,
        ]), Response::MODEL_POLICY_LIST);
    }
}
