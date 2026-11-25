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

class XList extends Base
{
    public static function getName(): string
    {
        return 'listVercelProjects';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/migrations/vercel/projects')
            ->desc('List Vercel projects')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.read')
            ->label('sdk', new Method(
                namespace: 'migrations',
                group: null,
                name: 'listVercelProjects',
                description: <<<EOT
                List the projects of the connected Vercel account, with the site settings each one would import with. Environment variables are only returned when getting a single project.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_MIGRATION_VERCEL_PROJECT_LIST,
                    )
                ]
            ))
            ->inject('response')
            ->inject('user')
            ->inject('dbForPlatform')
            ->inject('clientForOAuth2')
            ->callback($this->action(...));
    }

    public function action(
        Response $response,
        User $user,
        Database $dbForPlatform,
        Client $clientForOAuth2,
    ): void {
        $identity = $this->getIdentity($dbForPlatform, $user);
        $accessToken = $this->getAccessToken($clientForOAuth2, $dbForPlatform, $identity);

        try {
            $result = $this->api($clientForOAuth2, $accessToken, $identity, '/v9/projects', ['limit' => '100']);
        } catch (\Throwable) {
            throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'Unable to list your Vercel projects.');
        }

        $projects = \array_map(fn (array $project) => $this->toSite($project), $result['projects'] ?? []);

        $response->dynamic(new Document([
            'projects' => $projects,
            'total' => \count($projects),
        ]), Response::MODEL_MIGRATION_VERCEL_PROJECT_LIST);
    }
}
