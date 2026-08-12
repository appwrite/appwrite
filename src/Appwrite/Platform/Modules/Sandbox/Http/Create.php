<?php

namespace Appwrite\Platform\Modules\Sandbox\Http;

use Appwrite\Extend\Exception;
use Appwrite\Sandbox\Client;
use Appwrite\Sandbox\Exception as SandboxException;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
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
                Create a new sandbox: a live, isolated workspace to run commands in and read and write files from. Pass exactly one of `pool`, to claim a warm sandbox from a pool, or `image`, to start one from a container image. The returned URL serves the sandbox contract (`POST /execute`, `GET|PUT|DELETE /files/{path}`) and should be treated as a secret.
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
            ->param('pool', '', new Text(128), 'Sandbox pool to claim a warm sandbox from. Pass exactly one of `pool` or `image`.', true)
            ->param('image', '', new Text(256), 'Container image to start the sandbox from instead of claiming from a pool.', true)
            ->param('port', 3000, new Range(1, 65535), 'Port the sandbox contract is served on. Only used with `image`.', true)
            ->param('command', '', new Text(2048), 'Command to run instead of the installed sandbox agent.', true)
            ->param('variables', [], new Assoc(), 'Environment variables key-value JSON object.', true)
            ->param('ports', [], new ArrayList(new Range(1, 65535), 16), 'Extra ports the sandbox serves, each addressable at its own hostname in `urls`.', true)
            ->param('timeout', 300, new Range(0, 3600), 'Request timeout in seconds for calls to the sandbox URL. 0 removes the bound, for long-lived connections.', true)
            ->param('idleTimeout', -1, new Range(-1, 86400), 'Seconds without traffic before the sandbox is torn down. -1 uses the pool default, 0 keeps it live until deleted.', true)
            ->inject('response')
            ->inject('project')
            ->inject('sandboxes')
            ->callback($this->action(...));
    }

    public function action(
        string $sandboxId,
        string $pool,
        string $image,
        int $port,
        string $command,
        array $variables,
        array $ports,
        int $timeout,
        int $idleTimeout,
        Response $response,
        Document $project,
        Client $sandboxes,
    ): void {
        $sandboxId = $sandboxId === 'unique()' ? ID::unique() : $sandboxId;
        if (!\preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $sandboxId)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Sandbox ID must be lowercase alphanumeric with interior hyphens');
        }

        if (($pool === '') === ($image === '')) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Pass exactly one of pool or image');
        }

        $prefix = $this->prefix($project);
        $params = [
            'id' => $prefix . $sandboxId,
            'timeoutSeconds' => $timeout,
        ];

        if ($pool !== '') {
            $params['pool'] = $pool;
        }
        if ($image !== '') {
            $params['image'] = $image;
            $params['port'] = $port;
        }
        if ($command !== '') {
            $params['command'] = $command;
        }
        if ($variables !== []) {
            $params['environment'] = $variables;
        }
        if ($ports !== []) {
            $params['ports'] = \array_map(\intval(...), $ports);
        }
        if ($idleTimeout >= 0) {
            $params['idleTimeoutSeconds'] = $idleTimeout;
        }

        try {
            $status = $sandboxes->create($params);
        } catch (SandboxException $e) {
            // On create a 404 names an unknown pool, not a missing sandbox.
            throw $e->getCode() === 404
                ? new Exception(Exception::GENERAL_ARGUMENT_INVALID, $e->getMessage())
                : $this->mapError($e);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($this->document($status, $prefix), Response::MODEL_SANDBOX);
    }
}
