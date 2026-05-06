<?php

namespace Appwrite\Platform\Modules\Analytics\Http\Apps;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Domain;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createAnalyticsApp';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/analytics/apps')
            ->desc('Create analytics app')
            ->groups(['api', 'analytics'])
            ->label('scope', 'analytics.write')
            ->label('event', 'analytics.apps.[appId].create')
            ->label('audits.event', 'analyticsApp.create')
            ->label('audits.resource', 'analyticsApp/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'analytics',
                group: 'apps',
                name: 'createApp',
                description: 'Create a new analytics app to track a website or application.',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_ANALYTICS_APP,
                    ),
                ],
            ))
            ->param('appId', '', new CustomId(), 'Unique ID. Choose a custom ID or generate a random ID with `ID.unique()`. Max length is 36 chars.')
            ->param('name', '', new Text(128), 'Human-readable name for this app.')
            ->param('domain', '', new Domain(), 'Primary domain to track (e.g. example.com).')
            ->param('timezone', 'UTC', new Text(64), 'IANA timezone used for daily boundaries.', true)
            ->param('enabled', true, new Boolean(true), 'Whether tracking is enabled.', true)
            ->param('public', false, new Boolean(true), 'Whether stats are publicly viewable.', true)
            ->param('allowedOrigins', ['*'], new ArrayList(new Text(255), 64), 'Allowed origins for tracking script. Use ["*"] to allow all.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        string $name,
        string $domain,
        string $timezone,
        bool $enabled,
        bool $public,
        array $allowedOrigins,
        Response $response,
        Database $dbForProject,
    ): void {
        $appId = $appId === 'unique()' ? ID::unique() : $appId;
        $snippetId = 'snp_' . \bin2hex(\random_bytes(8));

        try {
            $app = $dbForProject->createDocument('analyticsApps', new Document([
                '$id' => $appId,
                'name' => $name,
                'domain' => $domain,
                'timezone' => $timezone,
                'enabled' => $enabled,
                'public' => $public,
                'allowedOrigins' => $allowedOrigins,
                'snippetId' => $snippetId,
            ]));
        } catch (DuplicateException) {
            throw new Exception(Exception::DOCUMENT_ALREADY_EXISTS);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($app, Response::MODEL_ANALYTICS_APP);
    }
}
