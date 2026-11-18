import { useState } from 'react'
import { useNavigate, useParams } from '@tanstack/react-router'
import { WafRuleAction } from '@appwrite.io/console'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { NumberInput } from '@/components/ui/number-input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Switch } from '@/components/ui/switch'
import { IdInput } from '@/components/ui/id-input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { ConditionsBuilder } from '../_components/ConditionsBuilder'
import { PriorityHint } from '../_components/PriorityHint'
import { RuleImpactPreview } from '../_components/RuleImpactPreview'
import { RuleSchedule } from '../_components/RuleSchedule'
import { useCreateFirewallRule } from '@/lib/react-query/hooks'
import {
  type FirewallCreatableAction,
  type FirewallRateLimitKey,
  type FirewallRateLimitStrategy,
  FIREWALL_RATE_LIMIT_KEYS,
  FIREWALL_RATE_LIMIT_KEY_DEFAULT,
  FIREWALL_RATE_LIMIT_STRATEGIES,
  FIREWALL_RATE_LIMIT_STRATEGY_DEFAULT,
  MAX_BUCKET_SIZE_MIN,
  MAX_BUCKET_SIZE_MAX,
  CHALLENGE_DIFFICULTY_MIN,
  CHALLENGE_DIFFICULTY_MAX,
  CHALLENGE_DIFFICULTY_DEFAULT,
  CHALLENGE_TTL_MIN,
  CHALLENGE_TTL_MAX,
  CHALLENGE_TTL_DEFAULT,
} from '@/lib/firewall/actions'
import {
  areFirewallConditionsComplete,
  createEmptyConditionDraft,
  firewallTabPath,
  parseFirewallListSearch,
  serializeFirewallConditions,
  type FirewallConditionDraft,
  type FirewallResourceSelection,
} from '@/lib/firewall/conditions'
import {
  defaultScheduleDraft,
  isScheduleDraftComplete,
  schedulePayload,
  todayIn,
} from '@/lib/firewall/schedule'
import { draftsFromUsageFilterMap } from '@/lib/firewall/usage'
import { getUserTimeZone } from '@/lib/timezones'
import { queryParamToMap } from '@/lib/table-filters'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { toast } from 'sonner'
import { Route } from '@/routes/_public/projects.$projectId.firewall.create'

function initialConditionsFromSearch(
  query: string | undefined,
): FirewallConditionDraft[] {
  if (!query) return [createEmptyConditionDraft()]
  return (
    draftsFromUsageFilterMap(queryParamToMap(query)) ?? [
      createEmptyConditionDraft(),
    ]
  )
}

const DEFAULT_FORM = {
  name: '',
  description: '',
  action: WafRuleAction.Deny as FirewallCreatableAction,
  priority: 100,
  enabled: true,
  limit: 100,
  interval: 60,
  rateLimitKey: FIREWALL_RATE_LIMIT_KEY_DEFAULT as FirewallRateLimitKey,
  strategy: FIREWALL_RATE_LIMIT_STRATEGY_DEFAULT as FirewallRateLimitStrategy,
  maxBucketSize: 50,
  difficulty: CHALLENGE_DIFFICULTY_DEFAULT,
  ttl: CHALLENGE_TTL_DEFAULT,
  location: '/',
  statusCode: 302,
}

export function View() {
  const t = useT()
  const navigate = useNavigate()
  const { projectId } = useParams({ strict: false })
  const {
    resourceType: initialResourceType,
    resourceId: initialResourceId,
    query: initialQuery,
  } = Route.useSearch()
  // The Firewall tab that opened the wizard sets the scope; it cannot change.
  const scope: FirewallResourceSelection = parseFirewallListSearch({
    resourceType: initialResourceType,
    resourceId: initialResourceId,
  }) ?? { resourceType: 'api' }
  const resourceId = scope.resourceType === 'api' ? undefined : scope.resourceId
  const createMutation = useCreateFirewallRule(projectId)
  const [ruleId, setRuleId] = useState<string | undefined>()
  const [form, setForm] = useState(DEFAULT_FORM)
  const [conditions, setConditions] = useState<FirewallConditionDraft[]>(() =>
    initialConditionsFromSearch(initialQuery),
  )
  const [schedule, setSchedule] = useState(() => {
    const timeZone = getUserTimeZone()
    return defaultScheduleDraft(timeZone, todayIn(timeZone))
  })

  const canSubmit =
    form.name.trim().length > 0 &&
    areFirewallConditionsComplete(conditions, scope.resourceType) &&
    (form.action !== WafRuleAction.RateLimit ||
      (form.limit > 0 &&
        form.interval > 0 &&
        (form.strategy !== 'tokenBucket' ||
          (form.maxBucketSize >= MAX_BUCKET_SIZE_MIN &&
            form.maxBucketSize <= MAX_BUCKET_SIZE_MAX)))) &&
    (form.action !== WafRuleAction.Challenge ||
      (form.difficulty >= CHALLENGE_DIFFICULTY_MIN &&
        form.difficulty <= CHALLENGE_DIFFICULTY_MAX &&
        form.ttl >= CHALLENGE_TTL_MIN &&
        form.ttl <= CHALLENGE_TTL_MAX)) &&
    (form.action !== WafRuleAction.Redirect ||
      (form.location.trim().length > 0 && form.statusCode > 0)) &&
    isScheduleDraftComplete(schedule)

  const handleClose = () => {
    navigate({ to: firewallTabPath(projectId!, scope) })
  }

  const handleSubmit = async () => {
    if (!canSubmit || !projectId) return
    try {
      await createMutation.mutateAsync({
        ruleId,
        action: form.action,
        resourceType: scope.resourceType,
        resourceId,
        name: form.name.trim(),
        description: form.description.trim() || undefined,
        priority: form.priority,
        enabled: form.enabled,
        conditions: serializeFirewallConditions(conditions),
        limit: form.limit,
        interval: form.interval,
        key: form.rateLimitKey,
        strategy: form.strategy,
        maxBucketSize: form.maxBucketSize,
        difficulty: form.difficulty,
        ttl: form.ttl,
        location: form.location.trim(),
        statusCode: form.statusCode,
        schedule: schedulePayload(schedule),
      })
      toast.success(t('Firewall rule created'))
      handleClose()
    } catch (error) {
      toast.error(
        getErrorMessage(error as Error, t('Failed to create firewall rule')),
      )
    }
  }

  const actionExtras =
    form.action === WafRuleAction.RateLimit ? (
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="firewall-strategy" className="text-[12px]">
            {t('Strategy')}
          </Label>
          <Select
            value={form.strategy}
            onValueChange={(value) =>
              setForm({
                ...form,
                strategy: value as FirewallRateLimitStrategy,
              })
            }
          >
            <SelectTrigger id="firewall-strategy" className="h-9 w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {FIREWALL_RATE_LIMIT_STRATEGIES.map((s) => (
                <SelectItem key={s.value} value={s.value}>
                  {t(s.label)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="firewall-key" className="text-[12px]">
            {t('Limit by')}
          </Label>
          <Select
            value={form.rateLimitKey}
            onValueChange={(value) =>
              setForm({ ...form, rateLimitKey: value as FirewallRateLimitKey })
            }
          >
            <SelectTrigger id="firewall-key" className="h-9 w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {FIREWALL_RATE_LIMIT_KEYS.map((k) => (
                <SelectItem key={k.value} value={k.value}>
                  {t(k.label)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="firewall-limit" className="text-[12px]">
            {t('Request limit')}
          </Label>
          <NumberInput
            id="firewall-limit"
            min={1}
            value={form.limit}
            onValueChange={(limit) => setForm({ ...form, limit })}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="firewall-interval" className="text-[12px]">
            {t('Interval (seconds)')}
          </Label>
          <NumberInput
            id="firewall-interval"
            min={1}
            value={form.interval}
            onValueChange={(interval) => setForm({ ...form, interval })}
          />
        </div>
        {form.strategy === 'tokenBucket' ? (
          <div className="space-y-1.5 sm:col-span-2">
            <Label htmlFor="firewall-max-bucket-size" className="text-[12px]">
              {t('Max bucket size')}
            </Label>
            <NumberInput
              id="firewall-max-bucket-size"
              min={MAX_BUCKET_SIZE_MIN}
              max={MAX_BUCKET_SIZE_MAX}
              value={form.maxBucketSize}
              onValueChange={(maxBucketSize) =>
                setForm({ ...form, maxBucketSize })
              }
            />
            <p className="text-[12px] text-muted-foreground">
              {t(
                'The largest burst allowed. Defaults to the request limit when left unset.',
              )}
            </p>
          </div>
        ) : null}
      </div>
    ) : form.action === WafRuleAction.Challenge ? (
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="firewall-difficulty" className="text-[12px]">
            {t('Difficulty')}
          </Label>
          <NumberInput
            id="firewall-difficulty"
            min={CHALLENGE_DIFFICULTY_MIN}
            max={CHALLENGE_DIFFICULTY_MAX}
            value={form.difficulty}
            onValueChange={(difficulty) => setForm({ ...form, difficulty })}
          />
          <p className="text-[12px] text-muted-foreground">
            {t('1 (easiest) to 5 (hardest).')}
          </p>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="firewall-ttl" className="text-[12px]">
            {t('TTL (seconds)')}
          </Label>
          <NumberInput
            id="firewall-ttl"
            min={CHALLENGE_TTL_MIN}
            max={CHALLENGE_TTL_MAX}
            value={form.ttl}
            onValueChange={(ttl) => setForm({ ...form, ttl })}
          />
          <p className="text-[12px] text-muted-foreground">
            {t('How long a visitor stays cleared after passing.')}
          </p>
        </div>
      </div>
    ) : form.action === WafRuleAction.Redirect ? (
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="firewall-location" className="text-[12px]">
            {t('Redirect location')}
          </Label>
          <Input
            id="firewall-location"
            value={form.location}
            onChange={(e) => setForm({ ...form, location: e.target.value })}
            placeholder="https://example.com"
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="firewall-status-code" className="text-[12px]">
            {t('Status code')}
          </Label>
          <NumberInput
            id="firewall-status-code"
            min={300}
            max={399}
            value={form.statusCode}
            onValueChange={(statusCode) => setForm({ ...form, statusCode })}
          />
        </div>
      </div>
    ) : null

  return (
    <WizardLayout
      title={t('Create firewall rule')}
      onClose={handleClose}
      fullscreen
      footerAlign="right"
      sidebar={
        <RuleImpactPreview
          conditions={conditions}
          action={form.action}
          resourceType={scope.resourceType}
          resourceId={resourceId}
          rateLimit={{
            strategy: form.strategy,
            limit: form.limit,
            interval: form.interval,
            maxBucketSize: form.maxBucketSize,
          }}
        />
      }
      footer={
        <>
          <Button
            variant="outline"
            onClick={handleClose}
            disabled={createMutation.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={!canSubmit || createMutation.isPending}
          >
            {t('Create rule')}
          </Button>
        </>
      }
    >
      <div className="space-y-5">
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-2 sm:col-span-2">
            <Label htmlFor="firewall-rule-name">{t('Rule name')}</Label>
            <Input
              id="firewall-rule-name"
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              placeholder={t('e.g., Deny suspicious IPs')}
            />
          </div>

          <div className="space-y-2 sm:col-span-2">
            <Label htmlFor="firewall-rule-description">
              {t('Description')}
            </Label>
            <Textarea
              id="firewall-rule-description"
              value={form.description}
              onChange={(e) =>
                setForm({ ...form, description: e.target.value })
              }
              placeholder={t('Optional description of what this rule does')}
              rows={2}
            />
          </div>
        </div>

        <ConditionsBuilder
          conditions={conditions}
          onChange={setConditions}
          resourceType={scope.resourceType}
          action={form.action}
          onActionChange={(next) => setForm({ ...form, action: next })}
          actionExtras={actionExtras}
        />

        <div className="space-y-2">
          <Label className="inline-flex items-center gap-1.5">
            {t('Priority')}
            <PriorityHint />
          </Label>
          <NumberInput
            min={0}
            value={form.priority}
            onValueChange={(priority) => setForm({ ...form, priority })}
          />
        </div>

        <div className="space-y-2">
          <Label>{t('Rule ID')}</Label>
          <IdInput value={ruleId} onChange={setRuleId} />
        </div>

        <RuleSchedule value={schedule} onChange={setSchedule} />

        <div className="flex items-center justify-between rounded-lg border border-border px-4 py-3">
          <div>
            <p className="text-[13px] font-medium text-foreground">
              {t('Enabled')}
            </p>
            <p className="mt-0.5 text-[12px] text-muted-foreground">
              {t('Rule will be active immediately')}
            </p>
          </div>
          <Switch
            checked={form.enabled}
            onCheckedChange={(enabled) => setForm({ ...form, enabled })}
          />
        </div>
      </div>
    </WizardLayout>
  )
}
