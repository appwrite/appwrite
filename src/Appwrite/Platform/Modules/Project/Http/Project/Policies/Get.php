<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Policies;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getProjectPolicy';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/project/policies/:policyId')
            ->desc('Get project policy')
            ->groups(['api', 'project'])
            ->label('scope', ['policies.read', 'project.policies.read'])
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'policies',
                name: 'getPolicy',
                description: <<<EOT
                Get a policy by its unique ID. This endpoint returns the current configuration for the requested project policy.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: [
                            Response::MODEL_POLICY_PASSWORD_DICTIONARY,
                            Response::MODEL_POLICY_PASSWORD_HISTORY,
                            Response::MODEL_POLICY_PASSWORD_PERSONAL_DATA,
                            Response::MODEL_POLICY_SESSION_ALERT,
                            Response::MODEL_POLICY_SESSION_DURATION,
                            Response::MODEL_POLICY_SESSION_INVALIDATION,
                            Response::MODEL_POLICY_SESSION_LIMIT,
                            Response::MODEL_POLICY_USER_LIMIT,
                            Response::MODEL_POLICY_MEMBERSHIP_PRIVACY,
                        ],
                    )
                ]
            ))
            ->param('policyId', '', new WhiteList([
                'password-dictionary',
                'password-history',
                'password-personal-data',
                'session-alert',
                'session-duration',
                'session-invalidation',
                'session-limit',
                'user-limit',
                'membership-privacy',
            ], true), 'Policy ID. Can be one of: password-dictionary, password-history, password-personal-data, session-alert, session-duration, session-invalidation, session-limit, user-limit, membership-privacy.')
            ->inject('response')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $policyId,
        Response $response,
        Document $project,
    ): void {
        $auths = $project->getAttribute('auths', []);

        [$policy, $model] = match ($policyId) {
            'password-dictionary' => [
                new Document([
                    '$id' => 'password-dictionary',
                    'enabled' => $auths['passwordDictionary'] ?? false,
                ]),
                Response::MODEL_POLICY_PASSWORD_DICTIONARY,
            ],
            'password-history' => [
                new Document([
                    '$id' => 'password-history',
                    'total' => $auths['passwordHistory'] ?? 0,
                ]),
                Response::MODEL_POLICY_PASSWORD_HISTORY,
            ],
            'password-personal-data' => [
                new Document([
                    '$id' => 'password-personal-data',
                    'enabled' => $auths['personalDataCheck'] ?? false,
                ]),
                Response::MODEL_POLICY_PASSWORD_PERSONAL_DATA,
            ],
            'session-alert' => [
                new Document([
                    '$id' => 'session-alert',
                    'enabled' => $auths['sessionAlerts'] ?? false,
                ]),
                Response::MODEL_POLICY_SESSION_ALERT,
            ],
            'session-duration' => [
                new Document([
                    '$id' => 'session-duration',
                    'duration' => $auths['duration'] ?? TOKEN_EXPIRATION_LOGIN_LONG,
                ]),
                Response::MODEL_POLICY_SESSION_DURATION,
            ],
            'session-invalidation' => [
                new Document([
                    '$id' => 'session-invalidation',
                    'enabled' => $auths['invalidateSessions'] ?? true,
                ]),
                Response::MODEL_POLICY_SESSION_INVALIDATION,
            ],
            'session-limit' => [
                new Document([
                    '$id' => 'session-limit',
                    'total' => $auths['maxSessions'] ?? 0,
                ]),
                Response::MODEL_POLICY_SESSION_LIMIT,
            ],
            'user-limit' => [
                new Document([
                    '$id' => 'user-limit',
                    'total' => $auths['limit'] ?? 0,
                ]),
                Response::MODEL_POLICY_USER_LIMIT,
            ],
            'membership-privacy' => [
                new Document([
                    '$id' => 'membership-privacy',
                    'userId' => $auths['membershipsUserId'] ?? false,
                    'userEmail' => $auths['membershipsUserEmail'] ?? false,
                    'userPhone' => $auths['membershipsUserPhone'] ?? false,
                    'userName' => $auths['membershipsUserName'] ?? false,
                    'userMFA' => $auths['membershipsMfa'] ?? false,
                ]),
                Response::MODEL_POLICY_MEMBERSHIP_PRIVACY,
            ],
            default => throw new \LogicException('Unknown policy ID: ' . $policyId),
        };

        $response->dynamic($policy, $model);
    }
}
