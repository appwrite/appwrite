<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Alert\Status;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Config\Config;

class Monitor extends Model
{
    public function __construct()
    {
        $alerts = Config::getParam('alerts', []);
        $types = \array_keys($alerts['types'] ?? []);
        $resources = \array_keys($alerts['resources'] ?? []);

        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Monitor ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Monitor creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Monitor update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Monitor name.',
                'default' => '',
                'example' => 'Bandwidth spike',
            ])
            ->addRule('projectId', [
                'type' => self::TYPE_STRING,
                'description' => 'Project ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('rules', [
                'type' => self::TYPE_STRING,
                'description' => 'Rules that must all hold for the monitor to fire, as query strings. A usage monitor has one comparing a metric with a threshold, optionally scoped with `equal(\'resourceType\')` and `equal(\'resourceId\')`.',
                'default' => [],
                'example' => ['{"method":"greaterThan","attribute":"network.outbound","values":[5000000]}'],
                'array' => true,
            ])
            ->addRule('type', [
                'type' => self::TYPE_ENUM,
                'description' => 'What the monitor checks: `usage` compares a usage metric with a threshold.',
                'default' => $types[0] ?? '',
                'example' => 'usage',
                'enum' => $types,
                'enumSDKName' => 'MonitorType',
            ])
            ->addRule('resourceType', [
                'type' => self::TYPE_ENUM,
                'description' => 'Type of the resource the monitor watches, from its rules: the project, or one database, bucket, function or site.',
                'default' => $resources[0] ?? '',
                'example' => 'database',
                'enum' => $resources,
                'enumSDKName' => 'MonitorResourceType',
            ])
            ->addRule('resourceId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the resource the monitor watches, from its rules. The project ID for a project-wide monitor.',
                'default' => '',
                'example' => 'main',
            ])
            ->addRule('window', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Seconds a counter metric is summed over, and a growth metric is measured across. Gauges compare their latest value. Usage is read a short lag behind the evaluation (`_APP_ALERTS_USAGE_LAG`, 120 seconds by default) so events still being written are counted by the next evaluation.',
                'default' => 3600,
                'example' => 3600,
            ])
            ->addRule('cooldown', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Minimum seconds between two team notifications of this monitor firing. Events fire on every change regardless.',
                'default' => 3600,
                'example' => 3600,
            ])
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the monitor is evaluated.',
                'default' => true,
                'example' => true,
            ])
            ->addRule('notify', [
                'type' => self::TYPE_ENUM,
                'description' => 'Organization roles whose confirmed members get an email and a console notification when the monitor fires and resolves. Empty when the alert events are the only delivery.',
                'default' => ['owner'],
                'example' => ['owner'],
                'array' => true,
                'enum' => Config::getParam('alerts', [])['roles'] ?? [],
                'enumSDKName' => 'MonitorNotifyRole',
            ])
            ->addRule('status', [
                'type' => self::TYPE_ENUM,
                'description' => 'Whether the monitor is firing, as of its last evaluation.',
                'default' => Status::Ok->value,
                'example' => Status::Ok->value,
                'enum' => Status::values(),
                'enumSDKName' => 'MonitorStatus',
            ])
            ->addRule('value', [
                'type' => self::TYPE_FLOAT,
                'description' => 'Metric value at the last evaluation. Null before the first evaluation, and when the last evaluation (`evaluatedAt`) found no usage recorded to read: without data a monitor is not compared, so it keeps its status and open alert.',
                'default' => null,
                'required' => false,
                'example' => 1250000,
            ])
            ->addRule('evaluatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Last evaluation date in ISO 8601 format.',
                'default' => null,
                'required' => false,
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('alertId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the alert the monitor is firing for. Empty while it is not firing.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('triggeredAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Date the monitor last announced an alert, in ISO 8601 format.',
                'default' => null,
                'required' => false,
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ]);
    }

    public function getName(): string
    {
        return 'Monitor';
    }

    public function getType(): string
    {
        return Response::MODEL_MONITOR;
    }
}
