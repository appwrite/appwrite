import { useEffect, useState } from 'react'
import { WafRuleAction, type Models } from '@appwrite.io/console'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { ConditionsBuilder } from './ConditionsBuilder'
import { FunctionSelector } from '@/components/global/shared/FunctionSelector'
import { SiteSelector } from '@/components/global/shared/SiteSelector'
import { RuleImpactPreview } from './RuleImpactPreview'
import { useUpdateFirewallRule } from '@/lib/react-query/hooks'
import {
  type FirewallCreatableAction,
  type FirewallRateLimitKey,
  type FirewallRateLimitStrategy,
  getRuleRateLimit,
  getRuleRedirect,
  getRuleChallenge,
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
  draftsFromParsedConditions,
  parseFirewallConditions,
  serializeFirewallConditions,
  type FirewallConditionDraft,
  type FirewallResourceType,
} from '@/lib/firewall/conditions'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { toast } from 'sonner'

interface UpdateRuleProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  rule: Models.WafRule | null
}

export function UpdateRule({
  open,
  onOpenChange,
  projectId,
  rule,
}: UpdateRuleProps) {
  const t = useT()
  const updateMutation = useUpdateFirewallRule(projectId)
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [resourceType, setResourceType] = useState<FirewallResourceType>('api')
  const [resourceId, setResourceId] = useState('')
  const [priority, setPriority] = useState(100)
  const [enabled, setEnabled] = useState(true)
  const [limit, setLimit] = useState(100)
  const [interval, setInterval] = useState(60)
  const [rateLimitKey, setRateLimitKey] = useState<FirewallRateLimitKey>(
    FIREWALL_RATE_LIMIT_KEY_DEFAULT,
  )
  const [strategy, setStrategy] = useState<FirewallRateLimitStrategy>(
    FIREWALL_RATE_LIMIT_STRATEGY_DEFAULT,
  )
  const [maxBucketSize, setMaxBucketSize] = useState(50)
  const [difficulty, setDifficulty] = useState(CHALLENGE_DIFFICULTY_DEFAULT)
  const [ttl, setTtl] = useState(CHALLENGE_TTL_DEFAULT)
  const [location, setLocation] = useState('/')
  const [statusCode, setStatusCode] = useState(302)
  const [conditions, setConditions] = useState<FirewallConditionDraft[]>([
    createEmptyConditionDraft(),
  ])

  useEffect(() => {
    if (!open || !rule) return
    setName(rule.name)
    setDescription(rule.description || '')
    setResourceType((rule.resourceType as FirewallResourceType) || 'api')
    setResourceId(rule.resourceId || '')
    setPriority(rule.priority)
    setEnabled(rule.enabled)
    const rateLimit = getRuleRateLimit(rule)
    setLimit(rateLimit?.limit ?? 100)
    setInterval(rateLimit?.interval ?? 60)
    setRateLimitKey(rateLimit?.key ?? FIREWALL_RATE_LIMIT_KEY_DEFAULT)
    setStrategy(rateLimit?.strategy ?? FIREWALL_RATE_LIMIT_STRATEGY_DEFAULT)
    // maxBucketSize comes back as 0 for non-token-bucket rules; seed the field
    // to the current limit so switching to token bucket has a sensible value.
    setMaxBucketSize(
      rateLimit?.maxBucketSize && rateLimit.maxBucketSize > 0
        ? rateLimit.maxBucketSize
        : (rateLimit?.limit ?? 100),
    )
    const challenge = getRuleChallenge(rule)
    setDifficulty(challenge?.difficulty ?? CHALLENGE_DIFFICULTY_DEFAULT)
    setTtl(challenge?.ttl ?? CHALLENGE_TTL_DEFAULT)
    const redirect = getRuleRedirect(rule)
    setLocation(redirect?.location ?? '/')
    setStatusCode(redirect?.statusCode ?? 302)
    setConditions(
      draftsFromParsedConditions(parseFirewallConditions(rule.conditions)),
    )
  }, [open, rule])

  if (!rule) return null

  const action = String(rule.action) as FirewallCreatableAction
  const needsResourceId = resourceType !== 'api'
  const canSubmit =
    name.trim().length > 0 &&
    (!needsResourceId || resourceId.trim().length > 0) &&
    areFirewallConditionsComplete(conditions) &&
    (action !== WafRuleAction.RateLimit ||
      (limit > 0 &&
        interval > 0 &&
        (strategy !== 'tokenBucket' ||
          (maxBucketSize >= MAX_BUCKET_SIZE_MIN &&
            maxBucketSize <= MAX_BUCKET_SIZE_MAX)))) &&
    (action !== WafRuleAction.Challenge ||
      (difficulty >= CHALLENGE_DIFFICULTY_MIN &&
        difficulty <= CHALLENGE_DIFFICULTY_MAX &&
        ttl >= CHALLENGE_TTL_MIN &&
        ttl <= CHALLENGE_TTL_MAX)) &&
    (action !== WafRuleAction.Redirect ||
      (location.trim().length > 0 && statusCode > 0))

  const handleSubmit = async () => {
    if (!canSubmit) return
    try {
      await updateMutation.mutateAsync({
        ruleId: rule.$id,
        action: rule.action,
        resourceType,
        resourceId: needsResourceId ? resourceId.trim() : '',
        name: name.trim(),
        description: description.trim(),
        priority,
        enabled,
        conditions: serializeFirewallConditions(conditions),
        limit,
        interval,
        key: rateLimitKey,
        strategy,
        maxBucketSize,
        difficulty,
        ttl,
        location: location.trim(),
        statusCode,
      })
      toast.success(t('Firewall rule updated'))
      onOpenChange(false)
    } catch (error) {
      toast.error(
        getErrorMessage(error as Error, t('Failed to update firewall rule')),
      )
    }
  }

  const actionExtras =
    action === WafRuleAction.RateLimit ? (
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label className="text-[12px]">{t('Strategy')}</Label>
          {/* Strategy is fixed at creation and cannot be changed on update. */}
          <div className="flex h-9 w-full items-center rounded-md border border-input bg-muted/40 px-3 text-[13px] text-muted-foreground">
            {t(
              FIREWALL_RATE_LIMIT_STRATEGIES.find((s) => s.value === strategy)
                ?.label ?? strategy,
            )}
          </div>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="update-firewall-key" className="text-[12px]">
            {t('Limit by')}
          </Label>
          <Select
            value={rateLimitKey}
            disabled={updateMutation.isPending}
            onValueChange={(value) =>
              setRateLimitKey(value as FirewallRateLimitKey)
            }
          >
            <SelectTrigger id="update-firewall-key" className="h-9 w-full">
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
          <Label htmlFor="update-firewall-limit" className="text-[12px]">
            {t('Request limit')}
          </Label>
          <Input
            id="update-firewall-limit"
            type="number"
            min={1}
            value={limit}
            onChange={(e) => setLimit(Number(e.target.value) || 1)}
            disabled={updateMutation.isPending}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="update-firewall-interval" className="text-[12px]">
            {t('Interval (seconds)')}
          </Label>
          <Input
            id="update-firewall-interval"
            type="number"
            min={1}
            value={interval}
            onChange={(e) => setInterval(Number(e.target.value) || 1)}
            disabled={updateMutation.isPending}
          />
        </div>
        {strategy === 'tokenBucket' ? (
          <div className="space-y-1.5 sm:col-span-2">
            <Label
              htmlFor="update-firewall-max-bucket-size"
              className="text-[12px]"
            >
              {t('Max bucket size')}
            </Label>
            <Input
              id="update-firewall-max-bucket-size"
              type="number"
              min={MAX_BUCKET_SIZE_MIN}
              max={MAX_BUCKET_SIZE_MAX}
              value={maxBucketSize}
              onChange={(e) =>
                setMaxBucketSize(Number(e.target.value) || MAX_BUCKET_SIZE_MIN)
              }
              disabled={updateMutation.isPending}
            />
            <p className="text-[12px] text-muted-foreground">
              {t(
                'The largest burst allowed. Defaults to the request limit when left unset.',
              )}
            </p>
          </div>
        ) : null}
      </div>
    ) : action === WafRuleAction.Challenge ? (
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="update-firewall-difficulty" className="text-[12px]">
            {t('Difficulty')}
          </Label>
          <Input
            id="update-firewall-difficulty"
            type="number"
            min={CHALLENGE_DIFFICULTY_MIN}
            max={CHALLENGE_DIFFICULTY_MAX}
            value={difficulty}
            onChange={(e) =>
              setDifficulty(Number(e.target.value) || CHALLENGE_DIFFICULTY_MIN)
            }
            disabled={updateMutation.isPending}
          />
          <p className="text-[12px] text-muted-foreground">
            {t('1 (easiest) to 5 (hardest).')}
          </p>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="update-firewall-ttl" className="text-[12px]">
            {t('TTL (seconds)')}
          </Label>
          <Input
            id="update-firewall-ttl"
            type="number"
            min={CHALLENGE_TTL_MIN}
            max={CHALLENGE_TTL_MAX}
            value={ttl}
            onChange={(e) =>
              setTtl(Number(e.target.value) || CHALLENGE_TTL_MIN)
            }
            disabled={updateMutation.isPending}
          />
          <p className="text-[12px] text-muted-foreground">
            {t('How long a visitor stays cleared after passing.')}
          </p>
        </div>
      </div>
    ) : action === WafRuleAction.Redirect ? (
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="update-firewall-location" className="text-[12px]">
            {t('Redirect location')}
          </Label>
          <Input
            id="update-firewall-location"
            value={location}
            onChange={(e) => setLocation(e.target.value)}
            disabled={updateMutation.isPending}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="update-firewall-status-code" className="text-[12px]">
            {t('Status code')}
          </Label>
          <Input
            id="update-firewall-status-code"
            type="number"
            min={300}
            max={399}
            value={statusCode}
            onChange={(e) => setStatusCode(Number(e.target.value) || 302)}
            disabled={updateMutation.isPending}
          />
        </div>
      </div>
    ) : null

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={t('Update firewall rule')}
      description={t('Modify the rule configuration and conditions.')}
      maxWidth="sm:max-w-xl"
    >
      <>
        <div className="border-t border-border shrink-0" />
        <div className="flex min-h-0 flex-1 flex-col">
          <div className="min-h-0 flex-1 overflow-y-auto">
            <div className="space-y-5 px-6 py-6">
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2 sm:col-span-2">
                  <Label htmlFor="update-firewall-rule-name">
                    {t('Rule name')}
                  </Label>
                  <Input
                    id="update-firewall-rule-name"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder={t('e.g., Deny suspicious IPs')}
                    disabled={updateMutation.isPending}
                  />
                </div>

                <div className="space-y-2 sm:col-span-2">
                  <Label htmlFor="update-firewall-rule-description">
                    {t('Description')}
                  </Label>
                  <Textarea
                    id="update-firewall-rule-description"
                    value={description}
                    onChange={(e) => setDescription(e.target.value)}
                    placeholder={t(
                      'Optional description of what this rule does',
                    )}
                    rows={2}
                    disabled={updateMutation.isPending}
                  />
                </div>
              </div>

              <div className="space-y-3">
                <div className="space-y-2">
                  <Label>{t('Resource type')}</Label>
                  <Select
                    value={resourceType}
                    disabled={updateMutation.isPending}
                    onValueChange={(value) => {
                      setResourceType(value as FirewallResourceType)
                      setResourceId('')
                    }}
                  >
                    <SelectTrigger className="h-9 w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {FIREWALL_RESOURCE_TYPES.map((resource) => (
                        <SelectItem key={resource.value} value={resource.value}>
                          {t(resource.label)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                {needsResourceId ? (
                  <div className="space-y-2">
                    <Label>{t('Resource ID')}</Label>
                    {resourceType === 'functions' ? (
                      <FunctionSelector
                        projectId={projectId}
                        value={resourceId}
                        onValueChange={setResourceId}
                        disabled={updateMutation.isPending}
                        triggerClassName="w-full justify-between font-normal"
                      />
                    ) : (
                      <SiteSelector
                        projectId={projectId}
                        value={resourceId}
                        onValueChange={setResourceId}
                        disabled={updateMutation.isPending}
                        triggerClassName="w-full justify-between font-normal"
                      />
                    )}
                  </div>
                ) : null}
              </div>

              <ConditionsBuilder
                conditions={conditions}
                onChange={setConditions}
                resourceType={resourceType}
                action={action}
                actionReadOnly
                actionExtras={actionExtras}
                disabled={updateMutation.isPending}
              />

              <div className="space-y-2">
                <Label htmlFor="update-firewall-priority">
                  {t('Priority')}
                </Label>
                <Input
                  id="update-firewall-priority"
                  type="number"
                  min={0}
                  value={priority}
                  onChange={(e) => setPriority(Number(e.target.value) || 0)}
                  disabled={updateMutation.isPending}
                />
                <p className="text-[12px] text-muted-foreground">
                  {t('Lower numbers are evaluated first.')}
                </p>
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
                  checked={enabled}
                  onCheckedChange={setEnabled}
                  disabled={updateMutation.isPending}
                />
              </div>

              <RuleImpactPreview
                conditions={conditions}
                action={action}
                resourceType={resourceType}
                resourceId={resourceId}
                showActivity
                rateLimit={{ strategy, limit, interval, maxBucketSize }}
              />
            </div>
          </div>

          <div className="flex shrink-0 items-center justify-start gap-2 border-t border-border bg-muted/30 px-6 py-4">
            <Button
              onClick={handleSubmit}
              disabled={!canSubmit || updateMutation.isPending}
            >
              {t('Update')}
            </Button>
            <Button
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={updateMutation.isPending}
            >
              {t('Cancel')}
            </Button>
          </div>
        </div>
      </>
    </BaseDrawer>
  )
}
