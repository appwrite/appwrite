<?php

namespace Appwrite\Platform\Modules\Alerts\Http\Monitors\Usage;

use Appwrite\Alert\Channel;
use Appwrite\Platform\Modules\Alerts\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Domains\Validator\PublicDomain;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Multiple;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

class Create extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'createUsageMonitor';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/monitors/usage')
            ->desc('Create usage monitor')
            ->groups(['api', 'alerts'])
            ->label('scope', 'monitors.write')
            ->label('limits', ['monitors'])
            ->label('audits.event', 'monitor.create')
            ->label('audits.resource', 'monitor/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'monitors',
                group: null,
                name: 'createUsageMonitor',
                description: <<<EOT
                Create a usage monitor. It reads a usage metric of the project, or of one of its databases or buckets, on a schedule, raises an alert when all its rules hold, and announces it on the selected channels when it fires and when it resolves. The webhook channel signs each request with the monitor's `webhookSecret`, which is only returned in this response.
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
            ->param('rules', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Rules that must all hold for the monitor to fire, as query strings generated with the Query class: one comparing a usage metric with a threshold in the metric\'s unit (bytes for storage and bandwidth, operations for reads and writes), and optionally `equal` rules on `resourceType` and `resourceId` to watch one database or bucket instead of the whole project.')
            ->param('window', 3600, new Range(60, 2592000), 'Seconds a counter metric is summed over, between 60 and 2592000. Gauges compare their latest value.', true)
            ->param('cooldown', 3600, new Range(0, 2592000), 'Minimum seconds between two firing announcements for this monitor, between 0 and 2592000.', true)
            ->param('channels', [Channel::Email->value, Channel::Console->value], new ArrayList(new WhiteList(Channel::values(), true), \count(Channel::cases())), 'Channels an alert is announced on when it fires and resolves.', true, enum: new Enum(name: 'MonitorChannel'))
            ->param('webhookUrl', '', fn () => new Multiple([new URL(['http', 'https']), new PublicDomain()], Multiple::TYPE_STRING), 'URL the webhook channel posts to. Required when the webhook channel is selected.', true)
            ->param('enabled', true, new Boolean(), 'Set to false to create the monitor without evaluating it.', true)
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * @param array<string> $rules
     * @param array<string> $channels
     */
    public function action(
        string $monitorId,
        string $name,
        array $rules,
        int $window,
        int $cooldown,
        array $channels,
        string $webhookUrl,
        bool $enabled,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Database $dbForProject,
        Authorization $authorization,
    ): void {
        $this->createMonitor(MONITOR_TYPE_USAGE, $monitorId, $name, $rules, $window, $cooldown, $channels, $webhookUrl, $enabled, $response, $project, $dbForPlatform, $dbForProject, $authorization);
    }
}
