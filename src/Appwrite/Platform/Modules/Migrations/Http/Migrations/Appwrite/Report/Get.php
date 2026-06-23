<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\Appwrite\Report;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Migration\Sources\Appwrite as AppwriteSource;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getAppwriteReport';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/migrations/appwrite/report')
            ->desc('Get Appwrite migration report')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.write')
            ->label('sdk', new Method(
                namespace: 'migrations',
                group: null,
                name: 'getAppwriteReport',
                description: '/docs/references/migrations/migration-appwrite-report.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_MIGRATION_REPORT,
                    )
                ]
            ))
            ->param('resources', [], new ArrayList(new WhiteList(AppwriteSource::getSupportedResources())), 'List of resources to migrate', enum: new Enum(name: 'AppwriteMigrationResource'))
            ->param('endpoint', '', new URL(), "Source's Appwrite Endpoint")
            ->param('projectID', '', new Text(512), "Source's Project ID")
            ->param('key', '', new Text(512), "Source's API Key")
            ->inject('response')
            ->inject('getDatabasesDB')
            ->callback($this->action(...));
    }

    public function action(
        array $resources,
        string $endpoint,
        string $projectID,
        string $key,
        Response $response,
        callable $getDatabasesDB
    ): void {
        try {
            $appwrite = new AppwriteSource($projectID, $endpoint, $key, $getDatabasesDB);
            $report = $appwrite->report($resources);
        } catch (\Throwable $e) {
            $code = (int) $e->getCode();

            // 401/403 are expected user errors (bad key, missing scope): surface them as a 4xx so
            // they are not published to Sentry.
            if ($code === 401 || $code === 403) {
                throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, $e->getMessage(), previous: $e);
            }

            // Genuine source failure — throw as 5xx so the central error handler publishes it.
            throw new Exception(
                Exception::MIGRATION_PROVIDER_ERROR,
                'Unable to connect to the migration source. Please verify your credentials and ensure the source is reachable from this server. Check for network restrictions such as firewalls, IP allowlists, or outbound connectivity limits.',
                code: 502,
                previous: $e
            );
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic(new Document($report), Response::MODEL_MIGRATION_REPORT);
    }
}
