<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Alert\Channel;
use Appwrite\Alert\Comparator;
use Appwrite\Alert\Status;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Config\Config;

class AlertRule extends Model
{
    public function __construct()
    {
        $alerts = Config::getParam('alerts', []);
        $metrics = \array_keys($alerts['metrics'] ?? []);
        $resources = \array_keys($alerts['resources'] ?? []);

        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Alert rule ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Alert rule creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Alert rule update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Alert rule name.',
                'default' => '',
                'example' => 'Bandwidth spike',
            ])
            ->addRule('projectId', [
                'type' => self::TYPE_STRING,
                'description' => 'Project ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('resourceType', [
                'type' => self::TYPE_ENUM,
                'description' => 'Type of the resource the rule watches.',
                'default' => $resources[0] ?? '',
                'example' => 'database',
                'enum' => $resources,
                'enumSDKName' => 'AlertResourceType',
            ])
            ->addRule('resourceId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the resource the rule watches. The project ID for project-wide rules.',
                'default' => '',
                'example' => 'main',
            ])
            ->addRule('metric', [
                'type' => self::TYPE_ENUM,
                'description' => 'Metric the rule evaluates.',
                'default' => $metrics[0] ?? '',
                'example' => 'databases.reads',
                'enum' => $metrics,
                'enumSDKName' => 'AlertMetric',
            ])
            ->addRule('comparator', [
                'type' => self::TYPE_ENUM,
                'description' => 'How the metric value is compared with the threshold.',
                'default' => Comparator::GreaterThan->value,
                'example' => Comparator::GreaterThan->value,
                'enum' => Comparator::values(),
                'enumSDKName' => 'AlertComparator',
            ])
            ->addRule('threshold', [
                'type' => self::TYPE_FLOAT,
                'description' => 'Value the metric is compared with, in the metric\'s unit.',
                'default' => 0,
                'example' => 1000000,
            ])
            ->addRule('window', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Seconds a counter metric is summed over. Gauges compare their latest value.',
                'default' => 3600,
                'example' => 3600,
            ])
            ->addRule('cooldown', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Minimum seconds between two notifications for this rule.',
                'default' => 3600,
                'example' => 3600,
            ])
            ->addRule('channels', [
                'type' => self::TYPE_ENUM,
                'description' => 'Channels notified when the rule changes state.',
                'default' => [],
                'example' => [Channel::Email->value, Channel::Webhook->value],
                'array' => true,
                'enum' => Channel::values(),
                'enumSDKName' => 'AlertChannel',
            ])
            ->addRule('webhookUrl', [
                'type' => self::TYPE_STRING,
                'description' => 'URL the webhook channel posts to.',
                'default' => '',
                'example' => 'https://example.com/alerts',
            ])
            ->addRule('webhookSecret', [
                'type' => self::TYPE_STRING,
                'description' => 'Key the webhook channel signs requests with: `X-Appwrite-Webhook-Signature` is `sha256=` followed by the hex HMAC-SHA256 of `<timestamp>.<body>`, where the timestamp is the `X-Appwrite-Webhook-Timestamp` header. Only returned on creation.',
                'default' => '',
                'example' => 'ad3d581ca230e2b7059c545e5a',
            ])
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the rule is evaluated.',
                'default' => true,
                'example' => true,
            ])
            ->addRule('status', [
                'type' => self::TYPE_ENUM,
                'description' => 'Whether the rule is firing, as of its last evaluation.',
                'default' => Status::Ok->value,
                'example' => Status::Ok->value,
                'enum' => Status::values(),
                'enumSDKName' => 'AlertStatus',
            ])
            ->addRule('value', [
                'type' => self::TYPE_FLOAT,
                'description' => 'Metric value at the last evaluation.',
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
            ->addRule('triggeredAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Date the rule last started firing and notified, in ISO 8601 format.',
                'default' => null,
                'required' => false,
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ]);
    }

    public function getName(): string
    {
        return 'AlertRule';
    }

    public function getType(): string
    {
        return Response::MODEL_ALERT_RULE;
    }
}
