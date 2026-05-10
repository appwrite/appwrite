<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\NHost\Report;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Migration\Sources\NHost;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Integer;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getNHostReport';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/migrations/nhost/report')
            ->desc('Get NHost migration report')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.write')
            ->label('sdk', new Method(
                namespace: 'migrations',
                group: null,
                name: 'getNHostReport',
                description: '/docs/references/migrations/migration-nhost-report.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_MIGRATION_REPORT,
                    )
                ]
            ))
            ->param('resources', [], new ArrayList(new WhiteList(NHost::getSupportedResources())), 'List of resources to migrate.')
            ->param('subdomain', '', new Text(512), 'Source\'s Subdomain.')
            ->param('region', '', new Text(512), 'Source\'s Region.')
            ->param('adminSecret', '', new Text(512), 'Source\'s Admin Secret.')
            ->param('database', '', new Text(512), 'Source\'s Database Name.')
            ->param('username', '', new Text(512), 'Source\'s Database Username.')
            ->param('password', '', new Text(512), 'Source\'s Database Password.')
            ->param('port', 5432, new Integer(true), 'Source\'s Database Port.', true)
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(
        array $resources,
        string $subdomain,
        string $region,
        string $adminSecret,
        string $database,
        string $username,
        string $password,
        int $port,
        Response $response
    ): void {
        try {
            $nhost = new NHost($subdomain, $region, $adminSecret, $database, $username, $password, $port);
            $report = $nhost->report($resources);
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
