<?php

namespace Appwrite\Deployment;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\VCS\Adapter\Git;

/**
 * Owns a deployment's lifecycle: upload bookkeeping, creating it and
 * dispatching the build, and canceling one in flight. The backend
 * (open-runtimes jobs-service vs the executor via the Builds worker) is
 * fixed for the request's lifetime by which implementation is wired up in
 * app/init/resources/request.php.
 */
abstract readonly class Backend
{
    public function __construct(
        protected Database $dbForProject,
        protected Document $project,
    ) {
    }

    /**
     * Saves chunked-upload progress onto the deployment — source path/size,
     * chunk counters, metadata. Never triggers a build; call createFromUpload()
     * once the upload is complete. Pass a single `Document` carrying every field
     * the deployment should end up with: either a fresh, not-yet-persisted
     * one (a plain `new Document([...])`), or the existing one fetched from
     * the database with more attributes set via `setAttributes()`. A new
     * document (one with no $sequence, i.e. not yet persisted — a document
     * only gets one from the database, never from `setAttributes()`) gets
     * the standard $permissions and resourceId/resourceInternalId/
     * resourceType merged in automatically.
     */
    public function upload(Document $resource, Document $deployment): Document
    {
        if ($deployment->getSequence() === null) {
            return $this->dbForProject->createDocument('deployments', new Document([
                '$permissions' => self::permissions(),
                ...self::resourceFields($resource),
                ...$deployment->getArrayCopy(),
            ]));
        }

        return $this->dbForProject->updateDocument('deployments', $deployment->getId(), $deployment);
    }

    /**
     * Finalizes the deployment document (see upload() for what `$deployment`
     * should carry) and dispatches it for building from its own uploaded
     * source. Deactivates any other active deployment for $resource, marks
     * it queued (and writes whatever else the backend needs, e.g.
     * buildPath). Returns the persisted, updated deployment.
     */
    abstract public function createFromUpload(Document $resource, Document $deployment): Document;

    /**
     * Same as createFromUpload(), but builds from a public git reference
     * (a template's repository) instead of the deployment's own uploaded
     * source. $reference is already resolved to a concrete commit/branch/tag
     * — resolving a version range (e.g. "0.3.*") is the caller's job, since
     * only it holds the GitHub client that can do so.
     */
    abstract public function createFromRef(
        Document $resource,
        Document $deployment,
        string $owner,
        string $repository,
        string $type,
        string $reference,
        string $rootDirectory = '',
    ): Document;

    /**
     * Same as createFromUpload(), but builds from a remote tarball at $url
     * (a VCS presigned URL) instead of the deployment's own uploaded source.
     */
    abstract public function createFromUrl(
        Document $resource,
        Document $deployment,
        string $url,
        string $rootDirectory = '',
    ): Document;

    /**
     * Root directory to extract from a VCS tarball, for createFromUrl().
     * GitHub archives wrap contents in a "{repo}-{ref}/" directory that the
     * jobs-service auto-strips; Gitea wraps them in "{repo}/", which survives
     * the strip, so the repository name must prefix the root directory.
     */
    public static function sourceSubdirectory(Git $vcs, string $repositoryName, string $rootDirectory): string
    {
        $rootDirectory = \trim($rootDirectory, '/');

        if ($vcs->getName() === 'gitea') {
            return \trim($repositoryName . '/' . $rootDirectory, '/');
        }

        return $rootDirectory;
    }

    /**
     * Best-effort cancel of an in-flight build. The deployment is already
     * marked canceled by the caller; this only needs to stop the backend from
     * still writing to it.
     */
    abstract public function cancel(string $deploymentId): void;

    /**
     * Deactivates any other active deployment for $resource before this one
     * goes live. Shared by both backends, called right before they mark the
     * deployment queued.
     */
    protected function deactivateOthers(Document $resource, Document $deployment): void
    {
        if (!$deployment->getAttribute('activate', false)) {
            return;
        }

        $others = $this->dbForProject->find('deployments', [
            Query::equal('activate', [true]),
            Query::equal('resourceId', [$resource->getId()]),
            Query::equal('resourceType', [$resource->getCollection()]),
            Query::notEqual('$id', $deployment->getId()),
        ]);

        foreach ($others as $other) {
            $this->dbForProject->updateDocument('deployments', $other->getId(), new Document([
                'activate' => false,
            ]));
        }
    }

    /**
     * @return array<string>
     */
    private static function permissions(): array
    {
        return [
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function resourceFields(Document $resource): array
    {
        return [
            'resourceInternalId' => $resource->getSequence(),
            'resourceId' => $resource->getId(),
            'resourceType' => $resource->getCollection(),
        ];
    }
}
