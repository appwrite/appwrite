<?php

namespace Appwrite\Platform\Modules\Sandbox\Http;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base as ComputeBase;
use OpenRuntimes\Orchestrator\Exception\ApiException;
use OpenRuntimes\Orchestrator\Model\SandboxStatus;
use Utopia\Database\Document;

abstract class Base extends ComputeBase
{
    /**
     * Sandboxes share one orchestrator across all projects, so ids are
     * namespaced by the project's internal id before they leave Appwrite.
     */
    protected function prefix(Document $project): string
    {
        return 'p' . $project->getSequence() . '-';
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
