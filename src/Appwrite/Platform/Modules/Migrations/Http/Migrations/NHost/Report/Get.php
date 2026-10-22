<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\NHost\Report;

use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\PublicHostname;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\SDK\Specification\Validator\PasswordFormat;
use Appwrite\Utopia\Response;
use Utopia\Client\Destinations\PublicInternet;
use Utopia\Database\Document;
use Utopia\Migration\Sources\NHost;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\AnyOf;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Identifier;
use Utopia\Validator\Integer;
use Utopia\Validator\Label;
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
            ->param('resources', [], new ArrayList(new WhiteList(NHost::getSupportedResources())), 'List of resources to migrate.', example: '["user"]', enum: new Enum(name: 'NHostMigrationResource'))
            ->param('subdomain', '', new Label(), 'Source\'s Subdomain.', example: '<SUBDOMAIN>')
            ->param('region', '', new Label(), 'Source\'s Region.', example: '<REGION>')
            ->param('adminSecret', '', new Text(512), 'Source\'s Admin Secret.')
            ->param('database', '', new AnyOf([new Identifier(63), new Label()], AnyOf::TYPE_STRING), 'Source\'s Database Name.', example: '<DATABASE>')
            ->param('username', '', new Text(512), 'Source\'s Database Username.')
            ->param('password', '', new PasswordFormat(new Text(512)), 'Source\'s Database Password.')
            ->param('port', 5432, new Integer(true), 'Source\'s Database Port.', true, example: '5432')
            ->inject('response')
            ->inject('publicHostname')
            ->inject('publicInternet')
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
        Response $response,
        PublicHostname $publicHostname,
        PublicInternet $publicInternet
    ): void {
        $nhost = (new NHost($subdomain, $region, $adminSecret, $database, $username, $password, (string) $port))
            ->setDestinations($publicInternet);

        try {
            $nhost->setDatabaseAddress($publicHostname->address($nhost->getDatabaseHost()));
        } catch (\InvalidArgumentException $exception) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $exception->getMessage());
        }

        try {
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
