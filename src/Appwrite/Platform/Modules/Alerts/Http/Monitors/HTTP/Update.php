<?php

namespace Appwrite\Platform\Modules\Alerts\Http\Monitors\HTTP;

use Appwrite\Alert\Probe\HTTP;
use Appwrite\Alert\Severity;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Alerts\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP as HTTPScope;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

class Update extends Base
{
    use HTTPScope;

    public static function getName(): string
    {
        return 'updateHttpMonitor';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/monitors/http/:monitorId')
            ->desc('Update HTTP monitor')
            ->groups(['api', 'alerts'])
            ->label('scope', 'monitors.write')
            ->label('audits.event', 'monitor.update')
            ->label('audits.resource', 'monitor/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'monitors',
                group: null,
                name: 'updateHttpMonitor',
                description: <<<EOT
                Update an HTTP monitor by its unique ID. Only the parameters passed change; the monitor's state, its last check and its open alert are kept.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_MONITOR,
                    ),
                ]
            ))
            ->param('monitorId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getMaxUidLength()), 'Monitor ID.', false, ['dbForPlatform'])
            ->param('name', null, new Nullable(new Text(128)), 'Monitor name. Max length: 128 chars.', true)
            ->param('url', null, new Nullable(new URL(['http', 'https'])), 'URL to request, with an `http` or `https` scheme.', true, example: 'https://example.com/health')
            ->param('method', null, new Nullable(new WhiteList(HTTP::METHODS, true)), 'HTTP method to request the URL with: `GET`, `HEAD`, `POST`, `PUT`, `PATCH`, `DELETE` or `OPTIONS`. Defaults to `GET`.', true)
            ->param('headers', null, new Nullable(new Assoc()), 'HTTP headers to send, as an object of header names and values.', true, example: '{"Accept":"application/json"}')
            ->param('body', null, new Nullable(new Text(16384, 0)), 'Request body to send. Max length: 16384 chars.', true)
            ->param('statusCodes', null, new Nullable(new ArrayList(new Range(100, 599), APP_LIMIT_ARRAY_PARAMS_SIZE)), 'Status codes the final response may have for the check to pass. Empty accepts any 2xx status.', true)
            ->param('certificateDays', null, new Nullable(new Range(0, 398)), 'For an HTTPS URL, days before its certificate expires from which the check fails, between 1 and 398, the longest a publicly trusted certificate is valid. 0 checks the certificate without warning before it expires.', true)
            ->param('interval', null, new Nullable(new Range(1, 2592000)), 'Seconds between two checks, between 1 and 2592000. The scheduler runs on a one second tick, so one second is the shortest interval.', true)
            ->param('timeout', null, new Nullable(new Range(1, 2592000)), 'Seconds a check may take before it fails, no longer than the interval.', true)
            ->param('cooldown', null, new Nullable(new Range(300, 2592000)), 'Minimum seconds between two team notifications of this monitor firing, between 300 and 2592000. Events fire on every change between firing and resolved regardless.', true)
            ->param('enabled', null, new Nullable(new Boolean()), 'Whether the monitor is checked.', true)
            ->param('notify', null, fn () => new Nullable(new ArrayList(new WhiteList(Config::getParam('alerts', [])['roles'] ?? [], true), APP_LIMIT_ARRAY_PARAMS_SIZE)), 'Organization roles whose confirmed members get an email and a console notification when the monitor fires and resolves. Each must be an organization role, such as `owner` or `developer`. Defaults to owners; pass an empty list to rely on the alert events alone.', true)
            ->param('severity', null, new Nullable(new WhiteList(Severity::values(), true)), 'How urgent the monitor\'s alerts are: `info`, `warning`, `error` or `critical`. Critical alerts may be delivered with higher urgency by the notification channels that support it, such as a push that rings through silent mode. Omit to keep the current one.', true)
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed>|null $headers
     * @param array<int>|null $statusCodes
     * @param array<string>|null $notify
     */
    public function action(
        string $monitorId,
        ?string $name,
        ?string $url,
        ?string $method,
        ?array $headers,
        ?string $body,
        ?array $statusCodes,
        ?int $certificateDays,
        ?int $interval,
        ?int $timeout,
        ?int $cooldown,
        ?bool $enabled,
        ?array $notify,
        ?string $severity,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        if ($headers !== null) {
            $headers = $this->encodeHeaders($headers);
        }

        $target = ['url' => $url, 'method' => $method, 'headers' => $headers, 'body' => $body, 'statusCodes' => $statusCodes === null ? null : \array_values(\array_unique(\array_map('intval', $statusCodes))), 'certificateDays' => $certificateDays];

        $this->updateProbeMonitor(MONITOR_TYPE_HTTP, $monitorId, $name, $target, $interval, $timeout, $cooldown, $enabled, $notify, $severity === null ? null : Severity::from($severity), $response, $project, $dbForPlatform, $authorization);
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
