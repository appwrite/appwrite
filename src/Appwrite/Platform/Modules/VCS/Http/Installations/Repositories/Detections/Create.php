<?php

namespace Appwrite\Platform\Modules\VCS\Http\Installations\Repositories\Detections;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Detection;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\InstallationTokens;
use Appwrite\Vcs\Validator\Directory;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\VCS\Exception\RepositoryNotFound;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createRepositoryDetection';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/vcs/github/installations/:installationId/detections')
            ->httpAlias('/v1/vcs/github/installations/:installationId/providerRepositories/:providerRepositoryId/detection')
            ->desc('Create repository detection')
            ->groups(['api', 'vcs'])
            ->label('scope', 'vcs.write')
            ->label('resourceType', RESOURCE_TYPE_VCS)
            ->label('sdk', new Method(
                namespace: 'vcs',
                group: 'repositories',
                name: 'createRepositoryDetection',
                description: '/docs/references/vcs/create-repository-detection.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: [
                            Response::MODEL_DETECTION_RUNTIME,
                            Response::MODEL_DETECTION_FRAMEWORK,
                        ],
                    ),
                ]
            ))
            ->param('installationId', '', new Text(256), 'Installation Id')
            ->param('providerRepositoryId', '', new Text(256), 'Repository Id')
            ->param('type', '', new WhiteList(['runtime', 'framework']), 'Detector type. Must be one of the following: runtime, framework', enum: new Enum(name: 'VCSDetectionType'))
            ->param('providerRootDirectory', '', new Directory(256), 'Path to Root Directory', true)
            ->inject('vcsFactory')
            ->inject('installationTokens')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $installationId,
        string $providerRepositoryId,
        string $type,
        string $providerRootDirectory,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Response $response,
        Database $dbForPlatform,
        Document $project
    ) {
        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if ($installation->isEmpty() || $installation->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $installation = $installationTokens->refreshForInstallation($installation, $dbForPlatform, $vcsFactory);
        $providerInstallationId = $installation->getAttribute('providerInstallationId');
        $vcs = $vcsFactory->fromInstallation($installation);

        $owner = $vcs->getOwnerName($providerInstallationId, (int) $providerRepositoryId);
        try {
            $repositoryName = $vcs->getRepositoryName($providerRepositoryId);
        } catch (RepositoryNotFound) {
            $repositoryName = '';
        }

        if (empty($repositoryName)) {
            throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
        }

        $files = $vcs->listRepositoryContents($owner, $repositoryName, $providerRootDirectory);
        $files = \array_column($files, 'name');
        $languages = $vcs->listRepositoryLanguages($owner, $repositoryName);

        $detection = new Detection($vcs, $owner, $repositoryName, \rtrim($providerRootDirectory, '/') . '/');
        $packager = $detection->packager($files);

        if ($type === 'framework') {
            $output = new Document([
                'framework' => '',
                'installCommand' => '',
                'buildCommand' => '',
                'outputDirectory' => '',
            ]);

            $framework = $detection->framework($files, $packager);

            if (!\is_null($framework)) {
                $output->setAttribute('installCommand', $framework->getInstallCommand());
                $output->setAttribute('buildCommand', $framework->getBuildCommand());
                $output->setAttribute('outputDirectory', $framework->getOutputDirectory());
                $framework = $framework->getName();
            } else {
                $framework = 'other';
            }

            $frameworks = Config::getParam('frameworks');
            if (!\in_array($framework, \array_keys($frameworks), true)) {
                $framework = 'other';
            }
            $output->setAttribute('framework', $framework);
        } else {
            $output = new Document([
                'runtime' => '',
                'commands' => '',
                'entrypoint' => '',
            ]);

            $runtime = $detection->runtime($files, $languages, $packager);

            if (!\is_null($runtime)) {
                $output->setAttribute('commands', $runtime->getCommands());
                $output->setAttribute('entrypoint', $runtime->getEntrypoint());
                $runtime = $runtime->getName();
            }

            if (!empty($runtime)) {
                $runtimes = Config::getParam('runtimes');
                $runtimeWithVersion = '';
                foreach ($runtimes as $runtimeKey => $runtimeConfig) {
                    if ($runtimeConfig['key'] === $runtime) {
                        $runtimeWithVersion = $runtimeKey;
                    }
                }

                if (empty($runtimeWithVersion)) {
                    throw new Exception(Exception::FUNCTION_RUNTIME_NOT_DETECTED);
                }

                $output->setAttribute('runtime', $runtimeWithVersion);
            } else {
                throw new Exception(Exception::FUNCTION_RUNTIME_NOT_DETECTED);
            }
        }

        $output->setAttribute('type', $type);
        $output->setAttribute('variables', $detection->variables($files));

        $response->dynamic($output, $type === 'framework' ? Response::MODEL_DETECTION_FRAMEWORK : Response::MODEL_DETECTION_RUNTIME);
    }
}
