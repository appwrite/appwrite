<?php

namespace Appwrite\Deployment\Backend;

use Appwrite\Deployment\Backend;
use Appwrite\Event\Message\Build as BuildMessage;
use Appwrite\Event\Publisher\Build as BuildPublisher;
use Executor\Executor as ExecutorClient;
use Utopia\Database\Database;
use Utopia\Database\Document;

readonly class Executor extends Backend
{
    final public function __construct(
        private BuildPublisher $publisherForBuilds,
        Database $dbForProject,
        Document $project,
        private ExecutorClient $executor,
        private array $platform,
    ) {
        parent::__construct($dbForProject, $project);
    }

    public function forProject(Database $dbForProject, Document $project): static
    {
        return new static($this->publisherForBuilds, $dbForProject, $project, $this->executor, $this->platform);
    }

    public function createFromUpload(Document $resource, Document $deployment): Document
    {
        return $this->dispatch($resource, $deployment, null);
    }

    public function createFromRef(
        Document $resource,
        Document $deployment,
        string $owner,
        string $repository,
        string $type,
        string $reference,
        string $rootDirectory = '',
    ): Document {
        // No presigned URL to fetch here — the Builds worker clones the
        // public repo itself via these raw git coordinates.
        return $this->dispatch($resource, $deployment, new Document([
            'ownerName' => $owner,
            'repositoryName' => $repository,
            'referenceType' => $type,
            'referenceValue' => $reference,
            'rootDirectory' => $rootDirectory,
        ]));
    }

    public function createFromUrl(
        Document $resource,
        Document $deployment,
        string $url,
        string $rootDirectory = '',
    ): Document {
        // The Builds worker clones the repo via the deployment's own
        // installationId/providerRepositoryId instead of $url.
        return $this->dispatch($resource, $deployment, null);
    }

    private function dispatch(Document $resource, Document $deployment, ?Document $template): Document
    {
        $deployment = $this->upload($resource, $deployment);
        $this->deactivateOthers($resource, $deployment);

        // The Builds worker promotes 'waiting' → 'building' on pickup.
        $deployment = $this->dbForProject->updateDocument('deployments', $deployment->getId(), new Document([
            'status' => 'waiting',
        ]));

        $this->publisherForBuilds->enqueue(new BuildMessage(
            project: $this->project,
            resource: $resource,
            deployment: $deployment,
            type: BUILD_TYPE_DEPLOYMENT,
            template: $template,
            platform: $this->platform,
        ));

        return $deployment;
    }

    public function cancel(string $deploymentId): void
    {
        $this->executor->deleteRuntime($this->project->getId(), $deploymentId . '-build');
    }
}
