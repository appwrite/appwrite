<?php

namespace Appwrite\Platform\Modules\Alerts\Http\Monitors\DNS;

use Appwrite\Alert\Probe\DNS;
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
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Hostname;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'createDnsMonitor';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/monitors/dns')
            ->desc('Create DNS monitor')
            ->groups(['api', 'alerts'])
            ->label('scope', 'monitors.write')
            ->label('limits', ['monitors'])
            ->label('audits.event', 'monitor.create')
            ->label('audits.resource', 'monitor/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'monitors',
                group: null,
                name: 'createDnsMonitor',
                description: <<<EOT
                Create a DNS monitor. It asks a resolver for a domain's records of one type on its own interval, and opens an alert when the resolver fails or returns no such record, or when none of them is the answer it expects. Each alert fires the `monitors.[monitorId].alerts.[alertId].firing` event when it opens and `.resolved` when a check passes again, for project webhooks, functions and realtime, and notifies the organization's members by email and in the console.
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
            ->param('domain', '', new Hostname(), 'Domain to query, such as `example.com`.')
            ->param('record', 'A', new WhiteList(DNS::RECORDS, true), 'Record type to query: `A`, `AAAA`, `CNAME`, `MX`, `TXT` or `NS`. Defaults to `A`.', true)
            ->param('resolver', '', new Text(128, 0), 'Resolver to query, as an IP address with an optional port, such as `1.1.1.1` or `[2606:4700:4700::1111]:53`. Defaults to the server\'s DNS resolver.', true)
            ->param('answer', '', new Text(1024, 0), 'Record value one of the answers must have for the check to pass, such as an IP address for an `A` record. Leave empty to accept any answer.', true)
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
     * @param array<string> $notify
     */
    public function action(
        string $monitorId,
        string $name,
        string $domain,
        string $record,
        string $resolver,
        string $answer,
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
        if ($resolver !== '') {
            $this->assertResolver($resolver);
        }

        $target = ['domain' => $domain, 'record' => $record, 'resolver' => $resolver, 'answer' => $answer];

        $this->createProbeMonitor(MONITOR_TYPE_DNS, $monitorId, $name, $target, $interval, $timeout, $cooldown, $enabled, $notify, Severity::from($severity), $response, $project, $dbForPlatform, $authorization);
    }

    private function assertResolver(string $resolver): void
    {
        [$address, $port] = DNS::server($resolver);
        if (\filter_var($address, FILTER_VALIDATE_IP) === false || $port < 1 || $port > 65535) {
            throw new Exception(Exception::MONITOR_TARGET_INVALID, "Resolver '{$resolver}' must be an IP address with an optional port.");
        }
    }
}
