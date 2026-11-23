<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\Supabase\Projects;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Supabase\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Client\Client;
use Utopia\Database\Database;
use Utopia\Database\Document;

class XList extends Base
{
    public static function getName(): string
    {
        return 'listSupabaseProjects';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/migrations/supabase/projects')
            ->desc('List Supabase projects')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.read')
            ->label('sdk', new Method(
                namespace: 'migrations',
                group: null,
                name: 'listSupabaseProjects',
                description: <<<EOT
                List the projects of the connected Supabase account.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_MIGRATION_SUPABASE_PROJECT_LIST,
                    )
                ]
            ))
            ->inject('request')
            ->inject('response')
            ->inject('user')
            ->inject('dbForPlatform')
            ->inject('clientForOAuth2')
            ->callback($this->action(...));
    }

    public function action(
        Request $request,
        Response $response,
        User $user,
        Database $dbForPlatform,
        Client $clientForOAuth2,
    ): void {
        $oauth2 = $this->oauth2($clientForOAuth2, $request);
        $accessToken = $this->getAccessToken($oauth2, $dbForPlatform, $this->getIdentity($dbForPlatform, $user));

        try {
            $projects = $oauth2->getProjects($accessToken);
        } catch (\Throwable) {
            throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'Unable to list your Supabase projects.');
        }

        $projects = \array_map(fn (array $project) => new Document([
            'projectRef' => $project['ref'] ?? '',
            'name' => $project['name'] ?? '',
            'region' => $project['region'] ?? '',
            'status' => $project['status'] ?? '',
        ]), $projects);

        $response->dynamic(new Document([
            'projects' => $projects,
            'total' => \count($projects),
        ]), Response::MODEL_MIGRATION_SUPABASE_PROJECT_LIST);
    }
}
