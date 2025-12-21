<?php

namespace Appwrite\Platform\Modules\Sites\Http\Deployments;

use Appwrite\Event\Build;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\Storage\Validator\File;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Utopia\Swoole\Request;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createDeployment';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/sites/:siteId/deployments')
            ->desc('Create deployment')
            ->groups(['api', 'sites'])
            ->label('scope', 'sites.write')
            ->label('resourceType', RESOURCE_TYPE_SITES)
            ->label('event', 'sites.[siteId].deployments.[deploymentId].create')
            ->label('audits.event', 'deployment.create')
            ->label('audits.resource', 'site/{request.siteId}')
            ->label('sdk', new Method(
                namespace: 'sites',
                group: 'deployments',
                name: 'createDeployment',
                description: <<<EOT
                Create a new site code deployment. Use this endpoint to upload a new version of your site code. To activate your newly uploaded code, you'll need to update the site's deployment to use your new deployment ID.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_ACCEPTED,
                        model: Response::MODEL_DEPLOYMENT,
                    )
                ],
                requestType: ContentType::MULTIPART,
                type: MethodType::UPLOAD,
                packaging: true,
            ))
            ->param('siteId', '', new UID(), 'Site ID.')
            ->param('installCommand', null, new Nullable(new Text(8192, 0)), 'Install Commands.', true)
            ->param('buildCommand', null, new Nullable(new Text(8192, 0)), 'Build Commands.', true)
            ->param('outputDirectory', null, new Nullable(new Text(8192, 0)), 'Output Directory.', true)
            ->param('code', [], new File(), 'Gzip file with your code package. When used with the Appwrite CLI, pass the path to your code directory, and the CLI will automatically package your code. Use a path that is within the current directory.', skipValidation: true)
            ->param('activate', false, new Boolean(true), 'Automatically activate the deployment when it is finished building.')
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('deviceForSites')
            ->inject('deviceForLocal')
            ->inject('queueForBuilds')
            ->inject('plan')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $siteId,
        ?string $installCommand,
        ?string $buildCommand,
        ?string $outputDirectory,
        mixed $code,
        mixed $activate,
        Request $request,
        Response $response,
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Event $queueForEvents,
        Device $deviceForSites,
        Device $deviceForLocal,
        Build $queueForBuilds,
        array $plan,
        Authorization $authorization
    ) {
        $activate = \strval($activate) === 'true' || \strval($activate) === '1';

        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        if ($installCommand === null) {
            $installCommand = $site->getAttribute('installCommand', '');
        }

        if ($buildCommand === null) {
            $buildCommand = $site->getAttribute('buildCommand', '');
        }

        if ($outputDirectory === null) {
            $outputDirectory = $site->getAttribute('outputDirectory', '');
        }

        $file = $request->getFiles('code');

        // GraphQL multipart spec adds files with index keys
        if (empty($file)) {
            $file = $request->getFiles(0);
        }

        if (empty($file)) {
            throw new Exception(Exception::STORAGE_FILE_EMPTY, 'No file sent');
        }

        $siteSizeLimit = (int) System::getEnv('_APP_COMPUTE_SIZE_LIMIT', '30000000');

        if (isset($plan['deploymentSize'])) {
            $siteSizeLimit = $plan['deploymentSize'] * 1000 * 1000;
        }

        $fileExt = new FileExt([FileExt::TYPE_GZIP]);
        $fileSizeValidator = new FileSize($siteSizeLimit);
        $upload = new Upload();

        // Make sure we handle a single file and multiple files the same way
        $fileName = (\is_array($file['name']) && isset($file['name'][0])) ? $file['name'][0] : $file['name'];
        $fileTmpName = (\is_array($file['tmp_name']) && isset($file['tmp_name'][0])) ? $file['tmp_name'][0] : $file['tmp_name'];
        $fileSize = (\is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];

        if (!$fileExt->isValid($file['name'])) { // Check if file type is allowed
            throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED);
        }

        $contentRange = $request->getHeader('content-range');
        $deploymentId = ID::unique();
        $chunk = 1;
        $chunks = 1;

        if (!empty($contentRange)) {
            $start = $request->getContentRangeStart();
            $end = $request->getContentRangeEnd();
            $fileSize = $request->getContentRangeSize();
            $deploymentId = $request->getHeader('x-appwrite-id', $deploymentId);
            // TODO make `end >= $fileSize` in next breaking version
            if (is_null($start) || is_null($end) || is_null($fileSize) || $end > $fileSize) {
                throw new Exception(Exception::STORAGE_INVALID_CONTENT_RANGE);
            }

            // TODO remove the condition that checks `$end === $fileSize` in next breaking version
            if ($end === $fileSize - 1 || $end === $fileSize) {
                //if it's a last chunks the chunk size might differ, so we set the $chunks and $chunk to notify it's last chunk
                $chunks = $chunk = -1;
            } else {
                // Calculate total number of chunks based on the chunk size i.e ($rangeEnd - $rangeStart)
                $chunks = (int) ceil($fileSize / ($end + 1 - $start));
                $chunk = (int) ($start / ($end + 1 - $start)) + 1;
            }
        }

        if (!$fileSizeValidator->isValid($fileSize) && $siteSizeLimit !== 0) { // Check if file size is exceeding allowed limit
            throw new Exception(Exception::STORAGE_INVALID_FILE_SIZE);
        }

        if (!$upload->isValid($fileTmpName)) {
            throw new Exception(Exception::STORAGE_INVALID_FILE);
        }

        // Save to storage
        $fileSize ??= $deviceForLocal->getFileSize($fileTmpName);
        $path = $deviceForSites->getPath($deploymentId . '.' . \pathinfo($fileName, PATHINFO_EXTENSION));
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        $metadata = ['content_type' => $deviceForLocal->getFileMimeType($fileTmpName)];
        if (!$deployment->isEmpty()) {
            $chunks = $deployment->getAttribute('sourceChunksTotal', 1);
            $metadata = $deployment->getAttribute('sourceMetadata', []);
            if ($chunk === -1) {
                $chunk = $chunks;
            }
        }

        $chunksUploaded = $deviceForSites->upload($fileTmpName, $path, $chunk, $chunks, $metadata);

        if (empty($chunksUploaded)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed moving file');
        }

        $type = $request->getHeader('x-sdk-language') === 'cli' ? 'cli' : 'manual';

        $commands = [];
        if (!empty($installCommand)) {
            $commands[] = $installCommand;
        }
        if (!empty($buildCommand)) {
            $commands[] = $buildCommand;
        }

        if ($chunksUploaded === $chunks) {
            if ($activate) {
                // Remove deploy for all other deployments.
                $activeDeployments = $dbForProject->find('deployments', [
                    Query::equal('activate', [true]),
                    Query::equal('resourceId', [$siteId]),
                    Query::equal('resourceType', ['sites'])
                ]);

                foreach ($activeDeployments as $activeDeployment) {
                    $activeDeployment->setAttribute('activate', false);
                    $dbForProject->updateDocument('deployments', $activeDeployment->getId(), $activeDeployment);
                }
            }

            $fileSize = $deviceForSites->getFileSize($path);

            if ($deployment->isEmpty()) {
                $deployment = $dbForProject->createDocument('deployments', new Document([
                    '$id' => $deploymentId,
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::any()),
                        Permission::delete(Role::any()),
                    ],
                    'resourceInternalId' => $site->getSequence(),
                    'resourceId' => $site->getId(),
                    'resourceType' => 'sites',
                    'buildCommands' => \implode(' && ', $commands),
                    'buildOutput' => $outputDirectory,
                    'adapter' => $site->getAttribute('adapter', ''),
                    'fallbackFile' => $site->getAttribute('fallbackFile', ''),
                    'sourcePath' => $path,
                    'sourceSize' => $fileSize,
                    'totalSize' => $fileSize,
                    'activate' => $activate,
                    'sourceMetadata' => $metadata,
                    'type' => $type,
                ]));

                $site = $site
                    ->setAttribute('latestDeploymentId', $deployment->getId())
                    ->setAttribute('latestDeploymentInternalId', $deployment->getSequence())
                    ->setAttribute('latestDeploymentCreatedAt', $deployment->getCreatedAt())
                    ->setAttribute('latestDeploymentStatus', $deployment->getAttribute('status', ''));
                $dbForProject->updateDocument('sites', $site->getId(), $site);

                $sitesDomain = System::getEnv('_APP_DOMAIN_SITES', '');
                $domain = ID::unique() . "." . $sitesDomain;

                // TODO: (@Meldiron) Remove after 1.7.x migration
                $isMd5 = System::getEnv('_APP_RULES_FORMAT') === 'md5';
                $ruleId = $isMd5 ? md5($domain) : ID::unique();

                $authorization->skip(
                    fn () => $dbForPlatform->createDocument('rules', new Document([
                        '$id' => $ruleId,
                        'projectId' => $project->getId(),
                        'projectInternalId' => $project->getSequence(),
                        'domain' => $domain,
                        'type' => 'deployment',
                        'trigger' => 'deployment',
                        'deploymentId' => $deployment->isEmpty() ? '' : $deployment->getId(),
                        'deploymentInternalId' => $deployment->isEmpty() ? '' : $deployment->getSequence(),
                        'deploymentResourceType' => 'site',
                        'deploymentResourceId' => $site->getId(),
                        'deploymentResourceInternalId' => $site->getSequence(),
                        'status' => 'verified',
                        'certificateId' => '',
                        'search' => implode(' ', [$ruleId, $domain]),
                        'owner' => 'Appwrite',
                        'region' => $project->getAttribute('region')
                    ]))
                );
            } else {
                $deployment = $dbForProject->updateDocument('deployments', $deploymentId, $deployment->setAttribute('sourceSize', $fileSize)->setAttribute('sourceMetadata', $metadata));
            }

            // Start the build
            $queueForBuilds
                ->setType(BUILD_TYPE_DEPLOYMENT)
                ->setResource($site)
                ->setDeployment($deployment);
        } else {
            if ($deployment->isEmpty()) {
                $deployment = $dbForProject->createDocument('deployments', new Document([
                    '$id' => $deploymentId,
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::any()),
                        Permission::delete(Role::any()),
                    ],
                    'resourceInternalId' => $site->getSequence(),
                    'resourceId' => $site->getId(),
                    'resourceType' => 'sites',
                    'buildCommands' => \implode(' && ', $commands),
                    'buildOutput' => $outputDirectory,
                    'adapter' => $site->getAttribute('adapter', ''),
                    'fallbackFile' => $site->getAttribute('fallbackFile', ''),
                    'sourcePath' => $path,
                    'sourceSize' => $fileSize,
                    'totalSize' => $fileSize,
                    'sourceChunksTotal' => $chunks,
                    'sourceChunksUploaded' => $chunksUploaded,
                    'activate' => $activate,
                    'sourceMetadata' => $metadata,
                    'type' => $type,
                ]));

                $site = $site
                    ->setAttribute('latestDeploymentId', $deployment->getId())
                    ->setAttribute('latestDeploymentInternalId', $deployment->getSequence())
                    ->setAttribute('latestDeploymentCreatedAt', $deployment->getCreatedAt())
                    ->setAttribute('latestDeploymentStatus', $deployment->getAttribute('status', ''));
                $dbForProject->updateDocument('sites', $site->getId(), $site);

                $sitesDomain = System::getEnv('_APP_DOMAIN_SITES', '');
                $domain = ID::unique() . "." . $sitesDomain;
                $ruleId = md5($domain);
                $authorization->skip(
                    fn () => $dbForPlatform->createDocument('rules', new Document([
                        '$id' => $ruleId,
                        'projectId' => $project->getId(),
                        'projectInternalId' => $project->getSequence(),
                        'domain' => $domain,
                        'type' => 'deployment',
                        'trigger' => 'deployment',
                        'deploymentId' => $deployment->isEmpty() ? '' : $deployment->getId(),
                        'deploymentInternalId' => $deployment->isEmpty() ? '' : $deployment->getSequence(),
                        'deploymentResourceType' => 'site',
                        'deploymentResourceId' => $site->getId(),
                        'deploymentResourceInternalId' => $site->getSequence(),
                        'status' => 'verified',
                        'certificateId' => '',
                        'search' => implode(' ', [$ruleId, $domain]),
                        'owner' => 'Appwrite',
                        'region' => $project->getAttribute('region')
                    ]))
                );
            } else {
                $deployment = $dbForProject->updateDocument('deployments', $deploymentId, $deployment->setAttribute('sourceChunksUploaded', $chunksUploaded)->setAttribute('sourceMetadata', $metadata));
            }
        }



        $metadata = null;

        $queueForEvents
            ->setParam('siteId', $site->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    }
}
