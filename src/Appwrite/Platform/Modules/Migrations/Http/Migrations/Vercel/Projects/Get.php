<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\Vercel\Projects;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Vercel\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Client\Client;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Validator\Text;

class Get extends Base
{
    public static function getName(): string
    {
        return 'getVercelProject';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/migrations/vercel/projects/:projectId')
            ->desc('Get Vercel project')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.read')
            ->label('sdk', new Method(
                namespace: 'migrations',
                group: null,
                name: 'getVercelProject',
                description: <<<EOT
                Get the site settings of a project of the connected Vercel account, with its production environment variables. Sensitive variables cannot be read back from Vercel, so they come with an empty value to fill in.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_MIGRATION_VERCEL_PROJECT,
                    )
                ]
            ))
            ->param('projectId', '', new Text(256), 'Vercel project ID.')
            ->inject('response')
            ->inject('user')
            ->inject('dbForPlatform')
            ->inject('clientForOAuth2')
            ->callback($this->action(...));
    }

    public function action(
        string $projectId,
        Response $response,
        User $user,
        Database $dbForPlatform,
        Client $clientForOAuth2,
    ): void {
        $identity = $this->getIdentity($dbForPlatform, $user);
        $accessToken = $this->getAccessToken($clientForOAuth2, $dbForPlatform, $identity);
        $path = '/v9/projects/' . \rawurlencode($projectId);

        try {
            $project = $this->api($clientForOAuth2, $accessToken, $identity, $path);
            $envs = $this->api($clientForOAuth2, $accessToken, $identity, $path . '/env', ['decrypt' => 'true']);
        } catch (\Throwable) {
            throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'Unable to read the Vercel project. Check that the Vercel integration can access it.');
        }

        $variables = [];
        foreach ($envs['envs'] ?? [] as $env) {
            if (!\in_array('production', $env['target'] ?? [], true)) {
                continue;
            }

            $sensitive = ($env['type'] ?? '') === 'sensitive';

            $variables[] = new Document([
                'key' => $env['key'] ?? '',
                'value' => $sensitive ? '' : ($env['value'] ?? ''),
                'secret' => $sensitive,
            ]);
        }

        $site = $this->toSite($project);
        $site->setAttribute('variables', $variables);

        $response->dynamic($site, Response::MODEL_MIGRATION_VERCEL_PROJECT);
    }
}
