<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\Supabase\Report;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Migration\Sources\Supabase;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Integer;
use Utopia\Validator\Text;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getSupabaseReport';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/migrations/supabase/report')
            ->desc('Get Supabase migration report')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.write')
            ->label('sdk', new Method(
                namespace: 'migrations',
                group: null,
                name: 'getSupabaseReport',
                description: '/docs/references/migrations/migration-supabase-report.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_MIGRATION_REPORT,
                    )
                ]
            ))
            ->param('resources', [], new ArrayList(new WhiteList(Supabase::getSupportedResources(), true)), 'List of resources to migrate')
            ->param('endpoint', '', new URL(), 'Source\'s Supabase Endpoint.')
            ->param('apiKey', '', new Text(512), 'Source\'s API Key.')
            ->param('databaseHost', '', new Text(512), 'Source\'s Database Host.')
            ->param('username', '', new Text(512), 'Source\'s Database Username.')
            ->param('password', '', new Text(512), 'Source\'s Database Password.')
            ->param('port', 5432, new Integer(true), 'Source\'s Database Port.', true)
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(
        array $resources,
        string $endpoint,
        string $apiKey,
        string $databaseHost,
        string $username,
        string $password,
        int $port,
        Response $response
    ): void {
        try {
            $supabase = new Supabase($endpoint, $apiKey, $databaseHost, 'postgres', $username, $password, $port);
            $report = $supabase->report($resources);
        } catch (\Throwable $e) {
            throw new Exception(
                Exception::MIGRATION_PROVIDER_ERROR,
                'Unable to connect to the migration source. Please verify your credentials and ensure the source is reachable from this server. Check for network restrictions such as firewalls, IP allowlists, or outbound connectivity limits.'
            );
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic(new Document($report), Response::MODEL_MIGRATION_REPORT);
    }
}
