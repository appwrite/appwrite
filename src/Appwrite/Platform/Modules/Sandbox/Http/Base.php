<?php

namespace Appwrite\Platform\Modules\Sandbox\Http;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Sandbox\Exception as SandboxException;
use Utopia\Database\Document;

abstract class Base extends Action
{
    /**
     * Sandboxes share one orchestrator across all projects, so ids are
     * namespaced by the project's internal id before they leave Appwrite.
     */
    protected function prefix(Document $project): string
    {
        return 'p' . $project->getSequence() . '-';
    }

    /**
     * @param array<string, mixed> $status An orchestrator sandbox status.
     */
    protected function document(array $status, string $prefix): Document
    {
        return new Document([
            '$id' => \substr((string)($status['id'] ?? ''), \strlen($prefix)),
            'status' => $status['status'] ?? '',
            'url' => $status['url'] ?? '',
            'urls' => $status['urls'] ?? [],
            'error' => $status['error'] ?? '',
        ]);
    }

    protected function mapError(SandboxException $error): Exception
    {
        return match ($error->getCode()) {
            404 => new Exception(Exception::SANDBOX_NOT_FOUND),
            409 => new Exception(Exception::SANDBOX_ALREADY_EXISTS),
            429 => new Exception(Exception::SANDBOX_LIMIT_EXCEEDED),
            400, 415 => new Exception(Exception::GENERAL_ARGUMENT_INVALID, $error->getMessage()),
            default => new Exception(Exception::GENERAL_SERVER_ERROR, $error->getMessage()),
        };
    }
}
