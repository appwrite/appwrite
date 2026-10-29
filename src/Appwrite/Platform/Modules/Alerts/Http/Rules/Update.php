<?php

namespace Appwrite\Platform\Modules\Alerts\Http\Rules;

use Appwrite\Alert\Channel;
use Appwrite\Alert\Comparator;
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
use Utopia\Domains\Validator\PublicDomain;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\FloatValidator;
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
        return 'updateRule';
    }

    public function __construct()
    {
        $alerts = Config::getParam('alerts', []);

        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/alerts/rules/:ruleId')
            ->desc('Update alert rule')
            ->groups(['api', 'alerts'])
            ->label('scope', 'alertRules.write')
            ->label('audits.event', 'alertRule.update')
            ->label('audits.resource', 'alertRule/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'alerts',
                group: 'rules',
                name: 'updateRule',
                description: <<<EOT
                Update an alert rule by its unique ID. Only the parameters passed change; the rule's state and webhook secret are kept.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ALERT_RULE,
                    ),
                ]
            ))
            ->param('ruleId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getMaxUidLength()), 'Rule ID.', false, ['dbForPlatform'])
            ->param('name', null, new Nullable(new Text(128)), 'Rule name. Max length: 128 chars.', true)
            ->param('metric', null, new Nullable(new WhiteList(\array_keys($alerts['metrics'] ?? []), true)), 'Metric the rule evaluates.', true, enum: new Enum(name: 'AlertMetric'))
            ->param('comparator', null, new Nullable(new WhiteList(Comparator::values(), true)), 'How the metric value is compared with the threshold.', true, enum: new Enum(name: 'AlertComparator'))
            ->param('threshold', null, new Nullable(new FloatValidator(true)), 'Value the metric is compared with, in the metric\'s unit: bytes for storage and bandwidth, operations for reads and writes.', true)
            ->param('resourceType', null, new Nullable(new WhiteList(\array_keys($alerts['resources'] ?? []), true)), 'Type of the resource the rule watches.', true, enum: new Enum(name: 'AlertResourceType'))
            ->param('resourceId', null, fn (Database $dbForPlatform) => new Nullable(new Text($dbForPlatform->getMaxUidLength(), 0)), 'ID of the database or bucket the rule watches. Ignored for project-wide rules.', true, ['dbForPlatform'])
            ->param('window', null, new Nullable(new Range(60, 2592000)), 'Seconds a counter metric is summed over, between 60 and 2592000.', true)
            ->param('cooldown', null, new Nullable(new Range(0, 2592000)), 'Minimum seconds between two firing notifications for this rule, between 0 and 2592000.', true)
            ->param('channels', null, new Nullable(new ArrayList(new WhiteList(Channel::values(), true), \count(Channel::cases()))), 'Channels notified when the rule starts and stops firing.', true, enum: new Enum(name: 'AlertChannel'))
            ->param('webhookUrl', null, fn () => new Nullable(new Multiple([new URL(['http', 'https']), new PublicDomain()], Multiple::TYPE_STRING)), 'URL the webhook channel posts to.', true)
            ->param('enabled', null, new Nullable(new Boolean()), 'Whether the rule is evaluated.', true)
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * @param array<string>|null $channels
     */
    public function action(
        string $ruleId,
        ?string $name,
        ?string $metric,
        ?string $comparator,
        float|int|string|null $threshold,
        ?string $resourceType,
        ?string $resourceId,
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
        $rule = $this->getRule($dbForPlatform, $authorization, $project, $ruleId);
        $changes = new Document();

        $resolvedMetric = $metric ?? (string) $rule->getAttribute('metric');
        $resolvedResourceType = $resourceType ?? (string) $rule->getAttribute('resourceType');
        $this->assertMetric($resolvedMetric, $resolvedResourceType);

        if ($resourceType !== null || $resourceId !== null) {
            [$resolvedId, $resolvedInternalId] = $this->resolveResource(
                $resolvedResourceType,
                $resourceId ?? (string) $rule->getAttribute('resourceId', ''),
                $project,
                $dbForProject,
                $authorization,
            );
            $changes
                ->setAttribute('resourceType', $resolvedResourceType)
                ->setAttribute('resourceId', $resolvedId)
                ->setAttribute('resourceInternalId', $resolvedInternalId);
        }

        if ($channels !== null) {
            $channels = \array_values(\array_unique($channels));
            $changes->setAttribute('channels', $channels);
        }

        $this->assertWebhook($channels ?? $rule->getAttribute('channels', []), $webhookUrl ?? (string) $rule->getAttribute('webhookUrl', ''));

        foreach ([
            'name' => $name,
            'metric' => $metric,
            'comparator' => $comparator,
            'threshold' => $threshold === null ? null : (float) $threshold,
            'window' => $window,
            'cooldown' => $cooldown,
            'webhookUrl' => $webhookUrl,
            'enabled' => $enabled,
        ] as $key => $value) {
            if ($value !== null) {
                $changes->setAttribute($key, $value);
            }
        }

        if (!$changes->isEmpty()) {
            $changes->setAttribute('search', $this->search(
                $rule->getId(),
                (string) $changes->getAttribute('name', $rule->getAttribute('name')),
                $resolvedMetric,
                $resolvedResourceType,
                (string) $changes->getAttribute('resourceId', $rule->getAttribute('resourceId')),
            ));

            $rule = $authorization->skip(fn () => $dbForPlatform->updateDocument('alertRules', $rule->getId(), $changes));
            $this->schedule($dbForPlatform, $rule);
        }

        $rule->removeAttribute('webhookSecret');

        $response->dynamic($rule, Response::MODEL_ALERT_RULE);
    }
}
