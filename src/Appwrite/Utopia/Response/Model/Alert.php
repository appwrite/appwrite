<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Alert\Comparator;
use Appwrite\Alert\State;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Config\Config;

class Alert extends Model
{
    public function __construct()
    {
        $alerts = Config::getParam('alerts', []);
        $metrics = \array_keys($alerts['metrics'] ?? []);
        $types = \array_keys($alerts['types'] ?? []);
        $resources = \array_keys($alerts['resources'] ?? []);

        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Alert ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Date the alert started firing, in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Alert update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('monitorId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the monitor that raised the alert.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Name of the monitor when the alert fired.',
                'default' => '',
                'example' => 'Bandwidth spike',
            ])
            ->addRule('status', [
                'type' => self::TYPE_ENUM,
                'description' => 'Whether the alert is still firing or has resolved.',
                'default' => State::Firing->value,
                'example' => State::Firing->value,
                'enum' => State::values(),
                'enumSDKName' => 'AlertStatus',
            ])
            ->addRule('resolvedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Date the alert resolved, in ISO 8601 format. Empty while it fires.',
                'default' => null,
                'required' => false,
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('rules', [
                'type' => self::TYPE_STRING,
                'description' => 'The monitor\'s rules when the alert fired, as query strings.',
                'default' => [],
                'example' => ['{"method":"greaterThan","attribute":"network.outbound","values":[5000000]}'],
                'array' => true,
            ])
            ->addRule('metric', [
                'type' => self::TYPE_ENUM,
                'description' => 'Metric the alert fired on.',
                'default' => $metrics[0] ?? '',
                'example' => 'network.outbound',
                'enum' => $metrics,
                'enumSDKName' => 'AlertMetric',
            ])
            ->addRule('comparator', [
                'type' => self::TYPE_ENUM,
                'description' => 'How the metric was compared with the threshold.',
                'default' => Comparator::GreaterThan->value,
                'example' => Comparator::GreaterThan->value,
                'enum' => Comparator::values(),
                'enumSDKName' => 'AlertComparator',
            ])
            ->addRule('threshold', [
                'type' => self::TYPE_FLOAT,
                'description' => 'Threshold the metric crossed, in the metric\'s unit.',
                'default' => 0,
                'example' => 5000000,
            ])
            ->addRule('value', [
                'type' => self::TYPE_FLOAT,
                'description' => 'Latest reading while the alert fired.',
                'default' => 0,
                'example' => 7250000,
            ])
            ->addRule('peak', [
                'type' => self::TYPE_FLOAT,
                'description' => 'Reading furthest past the threshold while the alert fired.',
                'default' => 0,
                'example' => 9100000,
            ])
            ->addRule('type', [
                'type' => self::TYPE_ENUM,
                'description' => 'Type of the monitor that raised the alert.',
                'default' => $types[0] ?? '',
                'example' => 'usage',
                'enum' => $types,
                'enumSDKName' => 'MonitorType',
            ])
            ->addRule('resourceType', [
                'type' => self::TYPE_ENUM,
                'description' => 'Type of the resource the alert fired on: the project, or one database, bucket, function or site.',
                'default' => $resources[0] ?? '',
                'example' => 'database',
                'enum' => $resources,
                'enumSDKName' => 'MonitorResourceType',
            ])
            ->addRule('resourceId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the resource the alert fired on. The project ID for a project-wide monitor.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('notifications', [
                'type' => self::TYPE_INTEGER,
                'description' => 'How many times the alert was announced as firing: once when it fired, and again after each cooldown while it kept firing.',
                'default' => 0,
                'example' => 1,
            ]);
    }

    public function getName(): string
    {
        return 'Alert';
    }

    public function getType(): string
    {
        return Response::MODEL_ALERT;
    }
}
