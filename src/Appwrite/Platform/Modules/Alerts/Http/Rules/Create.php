<?php

namespace Appwrite\Platform\Modules\Alerts\Http\Rules;

use Appwrite\Alert\Channel;
use Appwrite\Alert\Comparator;
use Appwrite\Alert\Status;
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
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Id;
use Utopia\Database\Validator\Authorization;
use Utopia\Domains\Validator\PublicDomain;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\FloatValidator;
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
        return 'createRule';
    }

    public function __construct()
    {
        $alerts = Config::getParam('alerts', []);

        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/alerts/rules')
            ->desc('Create alert rule')
            ->groups(['api', 'alerts'])
            ->label('scope', 'alertRules.write')
            ->label('limits', ['alertRules'])
            ->label('audits.event', 'alertRule.create')
            ->label('audits.resource', 'alertRule/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'alerts',
                group: 'rules',
                name: 'createRule',
                description: <<<EOT
                Create an alert rule. The rule compares a usage metric of the project, or of one of its databases or buckets, with a threshold on a schedule, and notifies the selected channels when it starts and stops firing. The webhook channel signs each request with the rule's `webhookSecret`, which is only returned in this response.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_ALERT_RULE,
                    ),
                ]
            ))
            ->param('ruleId', '', fn (Database $dbForPlatform) => new CustomId(false, $dbForPlatform->getMaxUidLength()), 'Rule ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForPlatform'])
            ->param('name', '', new Text(128), 'Rule name. Max length: 128 chars.')
            ->param('metric', '', new WhiteList(\array_keys($alerts['metrics'] ?? []), true), 'Metric the rule evaluates.', enum: new Enum(name: 'AlertMetric'))
            ->param('comparator', '', new WhiteList(Comparator::values(), true), 'How the metric value is compared with the threshold.', enum: new Enum(name: 'AlertComparator'))
            ->param('threshold', 0, new FloatValidator(true), 'Value the metric is compared with, in the metric\'s unit: bytes for storage and bandwidth, operations for reads and writes.')
            ->param('resourceType', 'project', new WhiteList(\array_keys($alerts['resources'] ?? []), true), 'Type of the resource the rule watches.', true, enum: new Enum(name: 'AlertResourceType'))
            ->param('resourceId', '', fn (Database $dbForPlatform) => new Text($dbForPlatform->getMaxUidLength(), 0), 'ID of the database or bucket the rule watches. Ignored for project-wide rules.', true, ['dbForPlatform'])
            ->param('window', 3600, new Range(60, 2592000), 'Seconds a counter metric is summed over, between 60 and 2592000. Gauges compare their latest value.', true)
            ->param('cooldown', 3600, new Range(0, 2592000), 'Minimum seconds between two firing notifications for this rule, between 0 and 2592000.', true)
            ->param('channels', [Channel::Email->value, Channel::Console->value], new ArrayList(new WhiteList(Channel::values(), true), \count(Channel::cases())), 'Channels notified when the rule starts and stops firing.', true, enum: new Enum(name: 'AlertChannel'))
            ->param('webhookUrl', '', fn () => new Multiple([new URL(['http', 'https']), new PublicDomain()], Multiple::TYPE_STRING), 'URL the webhook channel posts to. Required when the webhook channel is selected.', true)
            ->param('enabled', true, new Boolean(), 'Set to false to create the rule without evaluating it.', true)
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * @param array<string> $channels
     */
    public function action(
        string $ruleId,
        string $name,
        string $metric,
        string $comparator,
        float|int|string $threshold,
        string $resourceType,
        string $resourceId,
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
        $channels = \array_values(\array_unique($channels));

        $this->assertMetric($metric, $resourceType);
        $this->assertWebhook($channels, $webhookUrl);

        [$resourceId, $resourceInternalId] = $this->resolveResource($resourceType, $resourceId, $project, $dbForProject, $authorization);

        $ruleId = $ruleId === 'unique()' ? Id::unique() : $ruleId;

        $rule = new Document([
            '$id' => $ruleId,
            '$permissions' => [],
            'name' => $name,
            'teamId' => (string) $project->getAttribute('teamId', ''),
            'teamInternalId' => (string) $project->getAttribute('teamInternalId', ''),
            'projectId' => $project->getId(),
            'projectInternalId' => (string) $project->getSequence(),
            'region' => (string) $project->getAttribute('region', ''),
            'resourceType' => $resourceType,
            'resourceId' => $resourceId,
            'resourceInternalId' => $resourceInternalId,
            'metric' => $metric,
            'comparator' => $comparator,
            'threshold' => (float) $threshold,
            'window' => $window,
            'cooldown' => $cooldown,
            'channels' => $channels,
            'webhookUrl' => $webhookUrl,
            'webhookSecret' => \bin2hex(\random_bytes(32)),
            'enabled' => $enabled,
            'status' => Status::Ok->value,
            'search' => $this->search($ruleId, $name, $metric, $resourceType, $resourceId),
        ]);

        try {
            $rule = $authorization->skip(fn () => $dbForPlatform->createDocument('alertRules', $rule));
        } catch (DuplicateException) {
            throw new Exception(Exception::ALERT_RULE_ALREADY_EXISTS);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($rule, Response::MODEL_ALERT_RULE);
    }
}
