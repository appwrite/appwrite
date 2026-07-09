<?php

namespace Appwrite\Platform\Modules\VCS\Http\Installations\Repositories;

use Appwrite\Auth\OAuth2\Github as OAuth2Github;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\InstallationTokens;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createRepository';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/vcs/github/installations/:installationId/providerRepositories')
            ->desc('Create repository')
            ->groups(['api', 'vcs'])
            ->label('scope', 'vcs.write')
            ->label('resourceType', RESOURCE_TYPE_VCS)
            ->label('sdk', new Method(
                namespace: 'vcs',
                group: 'repositories',
                name: 'createRepository',
                description: '/docs/references/vcs/create-repository.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROVIDER_REPOSITORY,
                    )
                ]
            ))
            ->param('installationId', '', new Text(256), 'Installation Id')
            ->param('name', '', new Text(256), 'Repository name (slug)')
            ->param('private', '', new Boolean(false), 'Mark repository public or private')
            ->inject('vcsForInstallation')
            ->inject('installationTokens')
            ->inject('user')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(
        string $installationId,
        string $name,
        bool $private,
        callable $vcsForInstallation,
        InstallationTokens $installationTokens,
        Document $user,
        Response $response,
        Database $dbForPlatform
    ) {
        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if ($installation->getAttribute('personal', false) === true) {
            $oauth2 = new OAuth2Github(System::getEnv('_APP_VCS_GITHUB_CLIENT_ID', ''), System::getEnv('_APP_VCS_GITHUB_CLIENT_SECRET', ''), "");

            $identity = null;
            if (empty($installation->getAttribute('personalAccessToken')) || empty($installation->getAttribute('personalRefreshToken')) || empty($installation->getAttribute('personalAccessTokenExpiry'))) {
                $identity = $dbForPlatform->findOne('identities', [
                    Query::equal('provider', ['github']),
                    Query::equal('userInternalId', [$user->getSequence()]),
                ]);
                if ($identity->isEmpty()) {
                    throw new Exception(Exception::USER_IDENTITY_NOT_FOUND);
                }
            }

            $installation = $installationTokens->refresh($installation, $dbForPlatform, $oauth2, $identity);
            $accessToken = $installation->getAttribute('personalAccessToken');

            try {
                $repository = $oauth2->createRepository($accessToken, $name, $private);
            } catch (Exception $exception) {
                throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, "GitHub failed to process the request: " . $exception->getMessage());
            }
        } else {
            $providerInstallationId = $installation->getAttribute('providerInstallationId');
            $vcs = $vcsForInstallation($installation);
            $owner = $vcs->getOwnerName($providerInstallationId);

            try {
                $repository = $vcs->createRepository($owner, $name, $private);
            } catch (Exception $exception) {
                throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, "GitHub failed to process the request: " . $exception->getMessage());
            }
        }

        if (isset($repository['errors'])) {
            $message = $repository['message'] ?? 'Unknown error.';
            if (isset($repository['errors'][0])) {
                $message .= ' ' . $repository['errors'][0]['message'];
            }
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Provider Error: ' . $message);
        }

        if (isset($repository['message'])) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Provider Error: ' . $repository['message']);
        }

        $repository['id'] = \strval($repository['id']);
        $repository['pushedAt'] = $repository['pushed_at'] ?? '';
        $repository['organization'] = $installation->getAttribute('organization', '');
        $repository['provider'] = $installation->getAttribute('provider', '');
        $repository['providerInstallationId'] = $installation->getAttribute('providerInstallationId', '');
        $repository['authorized'] = true;

        $response->dynamic(new Document($repository), Response::MODEL_PROVIDER_REPOSITORY);
    }
}
