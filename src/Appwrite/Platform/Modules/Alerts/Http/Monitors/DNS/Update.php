<?php

namespace Appwrite\Platform\Modules\Alerts\Http\Monitors\DNS;

use Appwrite\Alert\Prober;
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
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Hostname;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateDnsMonitor';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/monitors/dns/:monitorId')
            ->desc('Update DNS monitor')
            ->groups(['api', 'alerts'])
            ->label('scope', 'monitors.write')
            ->label('audits.event', 'monitor.update')
            ->label('audits.resource', 'monitor/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'monitors',
                group: null,
                name: 'updateDnsMonitor',
                description: <<<EOT
                Update a DNS monitor by its unique ID. Only the parameters passed change; the monitor's state, its last check and its open alert are kept.
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
            ->param('domain', null, new Nullable(new Hostname()), 'Domain to query, such as `example.com`.', true)
            ->param('record', null, new Nullable(new WhiteList(Prober::RECORDS, true)), 'Record type to query: `A`, `AAAA`, `CNAME`, `MX`, `TXT` or `NS`. Defaults to `A`.', true)
            ->param('resolver', null, new Nullable(new Text(128, 0)), 'Resolver to query, as an IP address with an optional port, such as `1.1.1.1` or `[2606:4700:4700::1111]:53`. Defaults to the server\'s DNS resolver.', true)
            ->param('answer', null, new Nullable(new Text(1024, 0)), 'Record value the first answer must have for the check to pass, such as an IP address for an `A` record. Leave empty to accept any answer.', true)
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
     * @param array<string>|null $notify
     */
    public function action(
        string $monitorId,
        ?string $name,
        ?string $domain,
        ?string $record,
        ?string $resolver,
        ?string $answer,
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
        if ($resolver !== null && $resolver !== '') {
            $this->assertResolver($resolver);
        }

        $target = ['domain' => $domain, 'record' => $record, 'resolver' => $resolver, 'answer' => $answer];

        $this->updateProbeMonitor(MONITOR_TYPE_DNS, $monitorId, $name, $target, $interval, $timeout, $cooldown, $enabled, $notify, $severity === null ? null : Severity::from($severity), $response, $project, $dbForPlatform, $authorization);
    }

    private function assertResolver(string $resolver): void
    {
        [$address, $port] = Prober::server($resolver);
        if (\filter_var($address, FILTER_VALIDATE_IP) === false || $port < 1 || $port > 65535) {
            throw new Exception(Exception::MONITOR_TARGET_INVALID, "Resolver '{$resolver}' must be an IP address with an optional port.");
        }
    }
}
