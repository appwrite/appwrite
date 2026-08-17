import { useState } from 'react'
import { useNavigate, useParams } from '@tanstack/react-router'
import { WafRuleAction } from '@appwrite.io/console'
import { Globe, Server, Zap } from 'lucide-react'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Switch } from '@/components/ui/switch'
import { IdInput } from '@/components/ui/id-input'
import { FunctionSelector } from '@/components/global/shared/FunctionSelector'
import { SiteSelector } from '@/components/global/shared/SiteSelector'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { ConditionsBuilder } from '../_components/ConditionsBuilder'
import { RuleImpactPreview } from '../_components/RuleImpactPreview'
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
  FIREWALL_RESOURCE_TYPES,
  areFirewallConditionsComplete,
  createEmptyConditionDraft,
  serializeFirewallConditions,
  type FirewallConditionDraft,
  type FirewallResourceType,
} from '@/lib/firewall/conditions'
import { draftsFromUsageFilterMap } from '@/lib/firewall/usage'
import { queryParamToMap } from '@/lib/table-filters'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
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

const RESOURCE_TYPE_META: Record<
  FirewallResourceType,
  { description: string; icon: typeof Globe }
> = {
  api: {
    description: 'All API requests for this project',
    icon: Server,
  },
  functions: {
    description: 'Requests to a specific function',
    icon: Zap,
  },
  sites: {
    description: 'Requests to a specific site',
    icon: Globe,
  },
}

const DEFAULT_FORM = {
  name: '',
  description: '',
  action: WafRuleAction.Deny as FirewallCreatableAction,
  resourceType: 'api' as FirewallResourceType,
  resourceId: '',
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
    resourceType: initialResourceType = 'api',
    resourceId: initialResourceId,
    query: initialQuery,
  } = Route.useSearch()
  const createMutation = useCreateFirewallRule(projectId)
  const [ruleId, setRuleId] = useState<string | undefined>()
  const [form, setForm] = useState({
    ...DEFAULT_FORM,
    resourceType: initialResourceType,
    resourceId:
      initialResourceType !== 'api' && initialResourceId
        ? initialResourceId
        : '',
  })
  const [conditions, setConditions] = useState<FirewallConditionDraft[]>(() =>
    initialConditionsFromSearch(initialQuery),
  )

  const needsResourceId = form.resourceType !== 'api'
  const canSubmit =
    form.name.trim().length > 0 &&
    (!needsResourceId || form.resourceId.trim().length > 0) &&
    areFirewallConditionsComplete(conditions) &&
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
      (form.location.trim().length > 0 && form.statusCode > 0))

  const navigateToRules = (
    resourceType: FirewallResourceType = 'api',
    resourceId?: string,
  ) => {
    navigate({
      to: '/projects/$projectId/firewall',
      params: { projectId: projectId! },
      search:
        resourceType === 'api' || !resourceId?.trim()
          ? { resourceType: 'api' }
          : { resourceType, resourceId: resourceId.trim() },
    })
  }

  const handleClose = () => {
    navigateToRules(initialResourceType, initialResourceId)
  }

  const handleSubmit = async () => {
    if (!canSubmit || !projectId) return
    try {
      await createMutation.mutateAsync({
        ruleId,
        action: form.action,
        resourceType: form.resourceType,
        resourceId: needsResourceId ? form.resourceId.trim() : undefined,
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
      })
      toast.success(t('Firewall rule created'))
      navigateToRules(
        form.resourceType,
        needsResourceId ? form.resourceId.trim() : undefined,
      )
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
          <Input
            id="firewall-limit"
            type="number"
            min={1}
            value={form.limit}
            onChange={(e) =>
              setForm({
                ...form,
                limit: Number(e.target.value) || 1,
              })
            }
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="firewall-interval" className="text-[12px]">
            {t('Interval (seconds)')}
          </Label>
          <Input
            id="firewall-interval"
            type="number"
            min={1}
            value={form.interval}
            onChange={(e) =>
              setForm({
                ...form,
                interval: Number(e.target.value) || 1,
              })
            }
          />
        </div>
        {form.strategy === 'tokenBucket' ? (
          <div className="space-y-1.5 sm:col-span-2">
            <Label htmlFor="firewall-max-bucket-size" className="text-[12px]">
              {t('Max bucket size')}
            </Label>
            <Input
              id="firewall-max-bucket-size"
              type="number"
              min={MAX_BUCKET_SIZE_MIN}
              max={MAX_BUCKET_SIZE_MAX}
              value={form.maxBucketSize}
              onChange={(e) =>
                setForm({
                  ...form,
                  maxBucketSize: Number(e.target.value) || MAX_BUCKET_SIZE_MIN,
                })
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
          <Input
            id="firewall-difficulty"
            type="number"
            min={CHALLENGE_DIFFICULTY_MIN}
            max={CHALLENGE_DIFFICULTY_MAX}
            value={form.difficulty}
            onChange={(e) =>
              setForm({
                ...form,
                difficulty: Number(e.target.value) || CHALLENGE_DIFFICULTY_MIN,
              })
            }
          />
          <p className="text-[12px] text-muted-foreground">
            {t('1 (easiest) to 5 (hardest).')}
          </p>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="firewall-ttl" className="text-[12px]">
            {t('TTL (seconds)')}
          </Label>
          <Input
            id="firewall-ttl"
            type="number"
            min={CHALLENGE_TTL_MIN}
            max={CHALLENGE_TTL_MAX}
            value={form.ttl}
            onChange={(e) =>
              setForm({
                ...form,
                ttl: Number(e.target.value) || CHALLENGE_TTL_MIN,
              })
            }
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
          <Input
            id="firewall-status-code"
            type="number"
            min={300}
            max={399}
            value={form.statusCode}
            onChange={(e) =>
              setForm({
                ...form,
                statusCode: Number(e.target.value) || 302,
              })
            }
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
          resourceType={form.resourceType}
          resourceId={form.resourceId}
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

        <div className="space-y-3">
          <Label>{t('Resource type')}</Label>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            {FIREWALL_RESOURCE_TYPES.map((resource) => {
              const meta = RESOURCE_TYPE_META[resource.value]
              const Icon = meta.icon
              const selected = form.resourceType === resource.value
              return (
                <button
                  key={resource.value}
                  type="button"
                  onClick={() =>
                    setForm({
                      ...form,
                      resourceType: resource.value,
                      resourceId: '',
                    })
                  }
                  className={cn(
                    'flex w-full cursor-pointer items-start gap-3 rounded-xl border border-border bg-card/50 p-3.5 text-start transition-all hover:border-border/80 hover:bg-card/60',
                    selected &&
                      'border-primary ring-1 ring-primary/20 hover:border-primary',
                  )}
                >
                  <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                    <Icon className="h-4 w-4" />
                  </div>
                  <div className="min-w-0">
                    <p className="text-[13px] font-medium text-foreground">
                      {t(resource.label)}
                    </p>
                    <p className="mt-0.5 text-[12px] leading-snug text-muted-foreground">
                      {t(meta.description)}
                    </p>
                  </div>
                </button>
              )
            })}
          </div>

          {needsResourceId ? (
            <div className="space-y-2 pt-1">
              <Label>{t('Resource ID')}</Label>
              {form.resourceType === 'functions' ? (
                <FunctionSelector
                  projectId={projectId}
                  value={form.resourceId}
                  onValueChange={(resourceId) =>
                    setForm({ ...form, resourceId })
                  }
                  triggerClassName="w-full justify-between font-normal"
                />
              ) : (
                <SiteSelector
                  projectId={projectId}
                  value={form.resourceId}
                  onValueChange={(resourceId) =>
                    setForm({ ...form, resourceId })
                  }
                  triggerClassName="w-full justify-between font-normal"
                />
              )}
            </div>
          ) : null}
        </div>

        <ConditionsBuilder
          conditions={conditions}
          onChange={setConditions}
          resourceType={form.resourceType}
          action={form.action}
          onActionChange={(next) => setForm({ ...form, action: next })}
          actionExtras={actionExtras}
        />

        <div className="space-y-2">
          <Label>{t('Priority')}</Label>
          <Input
            type="number"
            min={0}
            value={form.priority}
            onChange={(e) =>
              setForm({
                ...form,
                priority: Number(e.target.value) || 0,
              })
            }
          />
          <p className="text-[12px] text-muted-foreground">
            {t('Lower numbers are evaluated first.')}
          </p>
        </div>

        <div className="space-y-2">
          <Label>{t('Rule ID')}</Label>
          <IdInput value={ruleId} onChange={setRuleId} />
        </div>

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
