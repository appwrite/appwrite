<?php

namespace Appwrite\Platform\Modules\Alerts\Http\Monitors\Usage;

use Appwrite\Alert\Channel;
use Appwrite\Platform\Modules\Alerts\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Domains\Validator\PublicDomain;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Multiple;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

class Update extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateUsageMonitor';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/monitors/usage/:monitorId')
            ->desc('Update usage monitor')
            ->groups(['api', 'alerts'])
            ->label('scope', 'monitors.write')
            ->label('audits.event', 'monitor.update')
            ->label('audits.resource', 'monitor/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'monitors',
                group: null,
                name: 'updateUsageMonitor',
                description: <<<EOT
                Update a usage monitor by its unique ID. Only the parameters passed change; the monitor's state, its open alert and its webhook secret are kept. Passing `rules` replaces all of them.
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
            ->param('rules', null, new Nullable(new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE)), 'Rules that must all hold for the monitor to fire, as query strings generated with the Query class: one comparing a usage metric with a threshold in the metric\'s unit (bytes for storage and bandwidth, operations for reads and writes), and optionally `equal` rules on `resourceType` and `resourceId` to watch one database or bucket instead of the whole project. Replaces the current rules.', true)
            ->param('window', null, new Nullable(new Range(60, 2592000)), 'Seconds a counter metric is summed over, between 60 and 2592000.', true)
            ->param('cooldown', null, new Nullable(new Range(0, 2592000)), 'Minimum seconds between two firing announcements for this monitor, between 0 and 2592000.', true)
            ->param('channels', null, new Nullable(new ArrayList(new WhiteList(Channel::values(), true), \count(Channel::cases()))), 'Channels an alert is announced on when it fires and resolves.', true, enum: new Enum(name: 'MonitorChannel'))
            ->param('webhookUrl', null, fn () => new Nullable(new Multiple([new URL(['http', 'https']), new PublicDomain()], Multiple::TYPE_STRING)), 'URL the webhook channel posts to.', true)
            ->param('enabled', null, new Nullable(new Boolean()), 'Whether the monitor is evaluated.', true)
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * @param array<string>|null $rules
     * @param array<string>|null $channels
     */
    public function action(
        string $monitorId,
        ?string $name,
        ?array $rules,
        ?int $window,
        ?int $cooldown,
        ?array $channels,
        ?string $webhookUrl,
        ?bool $enabled,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Database $dbForProject,
        Authorization $authorization,
    ): void {
        $this->updateMonitor(MONITOR_TYPE_USAGE, $monitorId, $name, $rules, $window, $cooldown, $channels, $webhookUrl, $enabled, $response, $project, $dbForPlatform, $dbForProject, $authorization);
    }
}
