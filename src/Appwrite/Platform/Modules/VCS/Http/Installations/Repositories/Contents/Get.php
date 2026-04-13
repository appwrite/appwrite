<?php

namespace Appwrite\Platform\Modules\VCS\Http\Installations\Repositories\Contents;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Text;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\VCS\Exception\RepositoryNotFound;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getRepositoryContents';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vcs/github/installations/:installationId/providerRepositories/:providerRepositoryId/contents')
            ->desc('Get files and directories of a VCS repository')
            ->groups(['api', 'vcs'])
            ->label('scope', 'vcs.read')
            ->label('resourceType', RESOURCE_TYPE_VCS)
            ->label('sdk', new Method(
                namespace: 'vcs',
                group: 'repositories',
                name: 'getRepositoryContents',
                description: '/docs/references/vcs/get-repository-contents.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_VCS_CONTENT_LIST,
                    )
                ]
            ))
            ->param('installationId', '', new Text(256), 'Installation Id')
            ->param('providerRepositoryId', '', new Text(256), 'Repository Id')
            ->param('providerRootDirectory', '', new Text(256, 0), 'Path to get contents of nested directory', true)
            ->param('providerReference', '', new Text(256, 0), 'Git reference (branch, tag, commit) to get contents from', true)
            ->inject('gitHub')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(
        string $installationId,
        string $providerRepositoryId,
        string $providerRootDirectory,
        string $providerReference,
        GitHub $github,
        Response $response,
        Database $dbForPlatform
    ) {
        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $providerInstallationId = $installation->getAttribute('providerInstallationId');
        $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
        $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);

        $owner = $github->getOwnerName($providerInstallationId);
        try {
            $repositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';
            if (empty($repositoryName)) {
                throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
            }
        } catch (RepositoryNotFound $e) {
            throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
        }

        $contents = $github->listRepositoryContents($owner, $repositoryName, $providerRootDirectory, $providerReference);

        $vcsContents = [];
        foreach ($contents as $content) {
            $isDirectory = false;
            if ($content['type'] === GitHub::CONTENTS_DIRECTORY) {
                $isDirectory = true;
            }

            $vcsContents[] = new Document([
                'isDirectory' => $isDirectory,
                'name' => $content['name'] ?? '',
                'size' => $content['size'] ?? 0
            ]);
        }

        $response->dynamic(new Document([
            'contents' => $vcsContents
        ]), Response::MODEL_VCS_CONTENT_LIST);
    }
}
