<?php

namespace Appwrite\Platform\Modules\Alerts\Http\Monitors\HTTP;

use Appwrite\Alert\Prober;
use Appwrite\Alert\Severity;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Alerts\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP as HTTPScope;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Boolean;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

class Create extends Base
{
    use HTTPScope;

    public static function getName(): string
    {
        return 'createHttpMonitor';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/monitors/http')
            ->desc('Create HTTP monitor')
            ->groups(['api', 'alerts'])
            ->label('scope', 'monitors.write')
            ->label('limits', ['monitors'])
            ->label('audits.event', 'monitor.create')
            ->label('audits.resource', 'monitor/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'monitors',
                group: null,
                name: 'createHttpMonitor',
                description: <<<EOT
                Create an HTTP monitor. It requests a URL on its own interval, following redirects, and opens an alert when the request fails or answers with a status it does not expect, or when the certificate of an HTTPS URL is about to expire. The response body is discarded as it arrives. Each alert fires the `monitors.[monitorId].alerts.[alertId].firing` event when it opens and `.resolved` when a check passes again, for project webhooks, functions and realtime, and notifies the organization's members by email and in the console.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_MONITOR,
                    ),
                ]
            ))
            ->param('monitorId', '', fn (Database $dbForPlatform) => new CustomId(false, $dbForPlatform->getMaxUidLength()), 'Monitor ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForPlatform'])
            ->param('name', '', new Text(128), 'Monitor name. Max length: 128 chars.')
            ->param('url', '', new URL(['http', 'https']), 'URL to request, with an `http` or `https` scheme.', false, example: 'https://example.com/health')
            ->param('method', 'GET', new WhiteList(Prober::METHODS, true), 'HTTP method to request the URL with: `GET`, `HEAD`, `POST`, `PUT`, `PATCH`, `DELETE` or `OPTIONS`. Defaults to `GET`.', true)
            ->param('headers', [], new Assoc(), 'HTTP headers to send, as an object of header names and values.', true, example: '{"Accept":"application/json"}')
            ->param('body', '', new Text(16384, 0), 'Request body to send. Max length: 16384 chars.', true)
            ->param('statusCodes', [], new ArrayList(new Range(100, 599), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Status codes the final response may have for the check to pass. Empty accepts any 2xx status.', true)
            ->param('certificateDays', 0, new Range(0, 398), 'For an HTTPS URL, days before its certificate expires from which the check fails, between 1 and 398, the longest a publicly trusted certificate is valid. 0 checks the certificate without warning before it expires.', true)
            ->param('interval', 60, new Range(1, 2592000), 'Seconds between two checks, between 1 and 2592000. The scheduler runs on a one second tick, so one second is the shortest interval.', true)
            ->param('timeout', 10, new Range(1, 2592000), 'Seconds a check may take before it fails, no longer than the interval.', true)
            ->param('cooldown', 3600, new Range(300, 2592000), 'Minimum seconds between two team notifications of this monitor firing, between 300 and 2592000. Events fire on every change between firing and resolved regardless.', true)
            ->param('enabled', true, new Boolean(), 'Set to false to create the monitor without checking it.', true)
            ->param('notify', ['owner'], fn () => new ArrayList(new WhiteList(Config::getParam('alerts', [])['roles'] ?? [], true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Organization roles whose confirmed members get an email and a console notification when the monitor fires and resolves. Each must be an organization role, such as `owner` or `developer`. Defaults to owners; pass an empty list to rely on the alert events alone.', true)
            ->param('severity', Severity::Warning->value, new WhiteList(Severity::values(), true), 'How urgent the monitor\'s alerts are: `info`, `warning`, `error` or `critical`. Critical alerts may be delivered with higher urgency by the notification channels that support it, such as a push that rings through silent mode.', true)
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $headers
     * @param array<int> $statusCodes
     * @param array<string> $notify
     */
    public function action(
        string $monitorId,
        string $name,
        string $url,
        string $method,
        array $headers,
        string $body,
        array $statusCodes,
        int $certificateDays,
        int $interval,
        int $timeout,
        int $cooldown,
        bool $enabled,
        array $notify,
        string $severity,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        $target = ['url' => $url, 'method' => $method, 'headers' => $this->encodeHeaders($headers), 'body' => $body, 'statusCodes' => \array_values(\array_unique(\array_map('intval', $statusCodes))), 'certificateDays' => $certificateDays];

        $this->createProbeMonitor(MONITOR_TYPE_HTTP, $monitorId, $name, $target, $interval, $timeout, $cooldown, $enabled, $notify, Severity::from($severity), $response, $project, $dbForPlatform, $authorization);
    }

    /**
     * Headers are stored as one JSON object of names and values.
     *
     * @param array<string, mixed> $headers
     */
    private function encodeHeaders(array $headers): string
    {
        foreach ($headers as $name => $value) {
            if (!\is_string($value) || \strpbrk($value . $name, "\r\n") !== false) {
                throw new Exception(Exception::MONITOR_TARGET_INVALID, "Header '{$name}' must be a single-line string.");
            }
        }

        try {
            $encoded = \json_encode((object) $headers, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Exception(Exception::MONITOR_TARGET_INVALID, 'Headers must be valid UTF-8.');
        }
        if (\strlen($encoded) > 16384) {
            throw new Exception(Exception::MONITOR_TARGET_INVALID, 'Headers must not exceed 16384 characters.');
        }

        return $encoded;
    }
}
