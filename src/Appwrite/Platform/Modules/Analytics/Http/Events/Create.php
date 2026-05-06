<?php

namespace Appwrite\Platform\Modules\Analytics\Http\Events;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Analytics\Storage\ClickHouse as AnalyticsClickHouse;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    // Salt for the visitor_id hash. POC uses a fixed salt; the production design
    // calls for a daily rotated salt stored in Redis.
    private const VISITOR_HASH_SALT = 'appwrite-analytics-poc-salt';

    public static function getName(): string
    {
        return 'createAnalyticsEvent';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/analytics/event')
            ->desc('Track analytics event')
            ->groups(['api', 'analytics'])
            ->label('scope', 'public')
            ->label('sdk', new Method(
                namespace: 'analytics',
                group: 'events',
                name: 'createEvent',
                description: 'Send a tracking event from a browser. Identifies the analytics app via the snippetId.',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_ACCEPTED,
                        model: Response::MODEL_NONE,
                    ),
                ],
            ))
            ->param('n', 'pageview', new WhiteList(['pageview', 'event'], true), 'Event name (pageview or event).')
            ->param('u', '', new URL(), 'Full page URL.')
            ->param('d', '', new Text(255), 'Domain hostname (e.g. example.com).')
            ->param('r', '', new Text(2048, 0), 'Referrer URL.', true)
            ->param('sid', '', new Text(64), 'Snippet ID identifying the analytics app.')
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('analyticsStorage')
            ->callback($this->action(...));
    }

    public function action(
        string $n,
        string $u,
        string $d,
        string $r,
        string $sid,
        Request $request,
        Response $response,
        Database $dbForProject,
        AnalyticsClickHouse $analyticsStorage,
    ): void {
        $apps = $dbForProject->find('analyticsApps', [
            Query::equal('snippetId', [$sid]),
            Query::limit(1),
        ]);

        if (empty($apps)) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        $app = $apps[0];

        if (!$app->getAttribute('enabled', true)) {
            $response->setStatusCode(Response::STATUS_CODE_ACCEPTED)->noContent();
            return;
        }

        $allowedOrigins = $app->getAttribute('allowedOrigins', ['*']);
        $origin = $request->getOrigin('');
        if (!\in_array('*', $allowedOrigins, true) && !\in_array($origin, $allowedOrigins, true)) {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, 'Origin not allowed for this analytics app.');
        }

        $ip = $request->getIP();
        $userAgent = $request->getUserAgent();
        $domain = $app->getAttribute('domain', $d);

        $visitorId = $this->hashVisitor($ip, $userAgent, $domain);

        $pathname = '/';
        $parsedUrl = \parse_url($u);
        if (\is_array($parsedUrl) && isset($parsedUrl['path'])) {
            $pathname = $parsedUrl['path'];
        }

        $hostname = $d;
        if (\is_array($parsedUrl) && isset($parsedUrl['host'])) {
            $hostname = $parsedUrl['host'];
        }

        $event = [
            'app_id' => $app->getId(),
            'name' => $n,
            'timestamp' => \gmdate('Y-m-d H:i:s'),
            'visitor_id' => $visitorId,
            'hostname' => $hostname,
            'pathname' => $pathname,
            'referrer' => $r,
            'country_code' => '',
            'screen_size' => '',
            'browser' => '',
            'operating_system' => '',
        ];

        try {
            $analyticsStorage->insertEvent($event);
        } catch (\Throwable $e) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to record analytics event: ' . $e->getMessage());
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->noContent();
    }

    private function hashVisitor(string $ip, string $userAgent, string $domain): string
    {
        $input = $ip . '|' . $userAgent . '|' . self::VISITOR_HASH_SALT . '|' . $domain;
        $hex = \hash('xxh64', $input);

        // ClickHouse UInt64 accepts numeric strings via JSONEachRow; converting from
        // hex via gmp avoids signed-int truncation on 64-bit boundaries.
        if (\function_exists('gmp_strval')) {
            return \gmp_strval(\gmp_init($hex, 16), 10);
        }

        return (string) \hexdec($hex);
    }
}
