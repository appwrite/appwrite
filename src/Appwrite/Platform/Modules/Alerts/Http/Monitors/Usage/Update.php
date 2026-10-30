<?php

namespace Appwrite\Platform\Modules\Alerts\Http\Monitors\Usage;

use Appwrite\Platform\Modules\Alerts\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

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
                Update a usage monitor by its unique ID. Only the parameters passed change; the monitor's state and its open alert are kept. Passing `rules` replaces all of them.
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
            ->param('rules', null, new Nullable(new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE)), 'Rules that must all hold for the monitor to fire, as query strings generated with the Query class: one comparing a usage metric, named as the usage endpoints name it (such as `network.outbound` or `databases.operations.reads`), with a threshold in the metric\'s unit (bytes for storage and bandwidth, MB-seconds for GB-hours, a count otherwise), and optionally `equal` rules on `resourceType` and `resourceId` to watch one database, bucket, function or site instead of the whole project. Replaces the current rules.', true)
            ->param('window', null, new Nullable(new Range(60, 2592000)), 'Seconds a counter metric is summed over, between 60 and 2592000.', true)
            ->param('cooldown', null, new Nullable(new Range(0, 2592000)), 'Minimum seconds between two team notifications of this monitor firing, between 0 and 2592000. Events fire on every change regardless.', true)
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
     */
    public function action(
        string $monitorId,
        ?string $name,
        ?array $rules,
        ?int $window,
        ?int $cooldown,
        ?bool $enabled,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Database $dbForProject,
        Authorization $authorization,
    ): void {
        $this->updateMonitor(MONITOR_TYPE_USAGE, $monitorId, $name, $rules, $window, $cooldown, $enabled, $response, $project, $dbForPlatform, $dbForProject, $authorization);
    }
}
