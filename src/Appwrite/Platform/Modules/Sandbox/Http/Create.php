<?php

namespace Appwrite\Platform\Modules\Sandbox\Http;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Validator\Specification;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use OpenRuntimes\Orchestrator\Exception\ApiException;
use OpenRuntimes\Orchestrator\Sandboxes;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Create extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'createSandbox';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/sandbox')
            ->desc('Create sandbox')
            ->groups(['api', 'sandbox'])
            ->label('scope', 'sandboxes.write')
            ->label('sdk', new Method(
                namespace: 'sandbox',
                group: 'sandbox',
                name: 'create',
                description: <<<EOT
                Create a new sandbox: a live, isolated workspace to run commands in and read and write files from, started from a container image. The returned URL serves the sandbox contract (`POST /execute`, `GET|PUT|DELETE /files/{path}`) and should be treated as a secret. Declare `ports` for anything else the sandbox serves, such as a dev server.

                The workspace is scratch and dies with the sandbox, except for `/workspace/persistent` — storage tied to the sandbox ID, which survives teardown and is restored when a sandbox is created under that ID again.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_SANDBOX,
                    ),
                ],
            ))
            ->param('sandboxId', 'unique()', new Text(36), 'Unique ID. Choose a custom ID or generate a random ID with `ID.unique()`. Lowercase alphanumeric with interior hyphens, max 36 chars.', true)
            ->param('image', '', new Text(256), 'Container image to start the sandbox from.')
            // Sandboxes are created ad hoc and in volume, so an unstated size
            // is the modest fallback rather than the largest box available.
            ->param('specification', fn (array $plan) => $this->getDefaultSpecification($plan, preferFallback: true), fn (array $plan) => new Specification(
                $plan,
                Config::getParam('specifications', []),
                System::getEnv('_APP_COMPUTE_CPUS', 0),
                System::getEnv('_APP_COMPUTE_MEMORY', 0)
            ), 'Compute specification sizing the sandbox.', true, ['plan'])
            ->param('command', '', new Text(2048), 'Command to run instead of the installed sandbox agent. It must serve the sandbox contract on port ' . self::CONTRACT_PORT . '.', true)
            ->param('variables', [], new Assoc(), 'Environment variables key-value JSON object.', true)
            ->param('ports', [], new ArrayList(new Range(1, 65535), 16), 'Extra ports the sandbox serves beyond the contract, each addressable at its own hostname in `urls`.', true)
            ->param('timeout', 300, new Range(0, 3600), 'Request timeout in seconds for calls to the sandbox URL. 0 removes the bound, for long-lived connections.', true)
            ->param('idleTimeout', 900, new Range(0, 86400), 'Seconds without traffic before the sandbox is torn down. 0 keeps it live until deleted.', true)
            ->inject('response')
            ->inject('project')
            ->inject('sandboxes')
            ->callback($this->action(...));
    }

    public function action(
        string $sandboxId,
        string $image,
        string $specification,
        string $command,
        array $variables,
        array $ports,
        int $timeout,
        int $idleTimeout,
        Response $response,
        Document $project,
        Sandboxes $sandboxes,
    ): void {
        $sandboxId = $sandboxId === 'unique()' ? ID::unique() : $sandboxId;
        if (!\preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $sandboxId)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Sandbox ID must be lowercase alphanumeric with interior hyphens');
        }

        $prefix = $this->prefix($project);
        $spec = Config::getParam('specifications', [])[$specification] ?? [];

        try {
            $status = $sandboxes->create(
                id: $prefix . $sandboxId,
                image: $image,
                port: self::CONTRACT_PORT,
                command: $command,
                cpu: (float) ($spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT),
                memory: (int) ($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT),
                environment: \array_map(\strval(...), $variables),
                ports: \array_values(\array_map(\intval(...), $ports)),
                volumes: $this->volumes($project, $sandboxId),
                timeoutSeconds: $timeout,
                idleTimeoutSeconds: $idleTimeout,
            );
        } catch (ApiException $e) {
            throw $this->mapError($e);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($this->document($status, $prefix), Response::MODEL_SANDBOX);
    }
}
