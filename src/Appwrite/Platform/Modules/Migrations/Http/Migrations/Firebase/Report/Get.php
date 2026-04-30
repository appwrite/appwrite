<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\Firebase\Report;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Migration\Sources\Firebase;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getFirebaseReport';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/migrations/firebase/report')
            ->desc('Get Firebase migration report')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.write')
            ->label('sdk', new Method(
                namespace: 'migrations',
                group: null,
                name: 'getFirebaseReport',
                description: '/docs/references/migrations/migration-firebase-report.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_MIGRATION_REPORT,
                    )
                ]
            ))
            ->param('resources', [], new ArrayList(new WhiteList(Firebase::getSupportedResources())), 'List of resources to migrate')
            ->param('serviceAccount', '', new Text(65536), 'JSON of the Firebase service account credentials')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(array $resources, string $serviceAccount, Response $response): void
    {
        $serviceAccount = json_decode($serviceAccount, true);

        if (empty($serviceAccount)) {
            throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'Invalid Service Account JSON');
        }

        if (!isset($serviceAccount['project_id']) || !isset($serviceAccount['client_email']) || !isset($serviceAccount['private_key'])) {
            throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'Invalid Service Account JSON');
        }

        try {
            $firebase = new Firebase($serviceAccount);
            $report = $firebase->report($resources);
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
