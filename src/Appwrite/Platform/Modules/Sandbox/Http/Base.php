<?php

namespace Appwrite\Platform\Modules\Sandbox\Http;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base as ComputeBase;
use OpenRuntimes\Orchestrator\Exception\ApiException;
use OpenRuntimes\Orchestrator\Model\SandboxStatus;
use OpenRuntimes\Orchestrator\Model\Volume;
use Utopia\Database\Document;
use Utopia\System\System;

abstract class Base extends ComputeBase
{
    /**
     * Where every sandbox serves the contract. Reserved rather than a
     * parameter: a caller overriding `command` serves it here too, so nothing
     * downstream has to ask which port the contract is on.
     */
    public const int CONTRACT_PORT = 3000;

    /**
     * Where each project's persistent volume is mounted. Everything else in
     * the workspace dies with the sandbox; this survives it.
     */
    public const string PERSISTENT_PATH = '/workspace/persistent';

    /**
     * Sandboxes share one orchestrator across all projects, so ids are
     * namespaced by the project's internal id before they leave Appwrite.
     */
    protected function prefix(Document $project): string
    {
        return 'p' . $project->getSequence() . '-';
    }

    /**
     * Storage that outlives a sandbox, one volume per project so no two
     * tenants ever share a filesystem. Unset _APP_SANDBOX_VOLUME and sandboxes
     * get a workspace that dies with them.
     *
     * @return list<Volume>
     */
    protected function volumes(Document $project): array
    {
        $volume = System::getEnv('_APP_SANDBOX_VOLUME', '');
        if ($volume === '') {
            return [];
        }

        return [new Volume(
            source: $volume . '-p' . $project->getSequence(),
            path: self::PERSISTENT_PATH,
        )];
    }

    protected function document(SandboxStatus $status, string $prefix): Document
    {
        return new Document([
            '$id' => \substr($status->id, \strlen($prefix)),
            'status' => $status->status->value,
            'url' => $status->url ?? '',
            'urls' => $status->urls,
            'error' => $status->error ?? '',
        ]);
    }

    protected function mapError(ApiException $error): Exception
    {
        return match ($error->statusCode) {
            404 => new Exception(Exception::SANDBOX_NOT_FOUND),
            409 => new Exception(Exception::SANDBOX_ALREADY_EXISTS),
            429 => new Exception(Exception::SANDBOX_LIMIT_EXCEEDED),
            400, 415 => new Exception(Exception::GENERAL_ARGUMENT_INVALID, $error->getMessage()),
            default => new Exception(Exception::GENERAL_SERVER_ERROR, $error->getMessage()),
        };
    }
}
