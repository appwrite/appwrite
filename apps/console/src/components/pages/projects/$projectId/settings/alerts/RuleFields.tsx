import { useQuery } from '@tanstack/react-query'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { NumberInput } from '@/components/ui/number-input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  bucketsQueryOptions,
  databasesQueryOptions,
  dedicatedDatabasesQueryOptions,
} from '@/lib/react-query/hooks'
import type {
  AlertChannel,
  AlertComparator,
  AlertMetric,
  AlertResourceType,
  AlertRule,
  AlertRuleInput,
} from '@/lib/appwrite/alerts'
import {
  BYTE_UNITS,
  COMPARATORS,
  COOLDOWNS,
  METRICS,
  RESOURCE_TYPES,
  WINDOWS,
  fromBytes,
  metricsFor,
  toBytes,
  type ByteUnit,
} from '@/lib/alerts/metrics'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'

/** Rule form state; `threshold` is in `byteUnit` for byte metrics. */
export type RuleForm = {
  name: string
  resourceType: AlertResourceType
  resourceId: string
  metric: AlertMetric
  comparator: AlertComparator
  threshold: number
  byteUnit: ByteUnit
  window: number
  cooldown: number
  channels: AlertChannel[]
  webhookUrl: string
}

const RESOURCE_PICKER_LIMIT = 100

const CHANNELS: { value: AlertChannel; label: string }[] = [
  { value: 'email', label: 'Email' },
  { value: 'console', label: 'Console notification' },
  { value: 'webhook', label: 'Webhook' },
]

export function emptyRuleForm(): RuleForm {
  return {
    name: '',
    resourceType: 'project',
    resourceId: '',
    metric: 'bandwidth.outbound',
    comparator: 'greaterThan',
    threshold: 10,
    byteUnit: 'GB',
    window: 3600,
    cooldown: 3600,
    channels: ['email', 'console'],
    webhookUrl: '',
  }
}

export function ruleFormFromRule(rule: AlertRule): RuleForm {
  const bytes = METRICS[rule.metric]?.unit === 'bytes'
  const split = bytes ? fromBytes(rule.threshold) : null
  return {
    name: rule.name,
    resourceType: rule.resourceType,
    resourceId: rule.resourceType === 'project' ? '' : rule.resourceId,
    metric: rule.metric,
    comparator: rule.comparator,
    threshold: split ? split.amount : rule.threshold,
    byteUnit: split ? split.unit : 'GB',
    window: rule.window,
    cooldown: rule.cooldown,
    channels: rule.channels,
    webhookUrl: rule.webhookUrl,
  }
}

/** Full API input for a form, with the threshold converted to the metric's unit. */
export function ruleInputFromForm(form: RuleForm): AlertRuleInput {
  const bytes = METRICS[form.metric]?.unit === 'bytes'
  const webhook = form.channels.includes('webhook')
  return {
    name: form.name.trim(),
    resourceType: form.resourceType,
    resourceId: form.resourceType === 'project' ? '' : form.resourceId.trim(),
    metric: form.metric,
    comparator: form.comparator,
    threshold: bytes ? toBytes(form.threshold, form.byteUnit) : form.threshold,
    window: form.window,
    cooldown: form.cooldown,
    channels: form.channels,
    webhookUrl: webhook ? form.webhookUrl.trim() : '',
  }
}

/** Why the form cannot be submitted, or undefined when it can. */
export function ruleFormError(
  form: RuleForm,
  t: (text: string) => string,
): string | undefined {
  if (!form.name.trim()) return t('Enter a rule name.')
  if (form.resourceType !== 'project' && !form.resourceId.trim()) {
    return t('Select the resource to watch.')
  }
  if (!Number.isFinite(form.threshold) || form.threshold < 0) {
    return t('Enter a threshold of 0 or more.')
  }
  if (form.channels.length === 0) return t('Select at least one channel.')
  if (form.channels.includes('webhook') && !form.webhookUrl.trim()) {
    return t('Enter a webhook URL.')
  }
  return undefined
}

function durationOptions(
  presets: { value: number; label: string }[],
  current: number,
  t: (text: string) => string,
) {
  const options = presets.map((preset) => ({
    value: preset.value,
    label: t(preset.label),
  }))
  if (!options.some((option) => option.value === current)) {
    options.push({ value: current, label: `${current}s` })
    options.sort((a, b) => a.value - b.value)
  }
  return options
}

function ResourcePicker({
  projectId,
  resourceType,
  value,
  onChange,
  disabled,
}: {
  projectId: string
  resourceType: Exclude<AlertResourceType, 'project'>
  value: string
  onChange: (resourceId: string) => void
  disabled?: boolean
}) {
  const t = useT()
  const databases = useQuery({
    ...databasesQueryOptions(projectId, 0, RESOURCE_PICKER_LIMIT),
    enabled: resourceType === 'database',
  })
  const buckets = useQuery({
    ...bucketsQueryOptions(projectId, 0, RESOURCE_PICKER_LIMIT),
    enabled: resourceType === 'bucket',
  })
  const dedicated = useQuery({
    ...dedicatedDatabasesQueryOptions(projectId),
    enabled: resourceType === 'dedicatedDatabase',
  })

  const query =
    resourceType === 'database'
      ? databases
      : resourceType === 'bucket'
        ? buckets
        : dedicated
  const items: { $id: string; name: string }[] =
    resourceType === 'database'
      ? (databases.data?.databases ?? [])
      : resourceType === 'bucket'
        ? (buckets.data?.buckets ?? [])
        : (dedicated.data?.databases ?? [])

  if (query.isError) {
    return (
      <div className="flex items-center gap-2 text-[13px] text-destructive">
        <span>{t('Could not load resources.')}</span>
        <button
          type="button"
          className="underline underline-offset-2"
          onClick={() => void query.refetch()}
        >
          {t('Retry')}
        </button>
      </div>
    )
  }

  if (!query.isLoading && items.length === 0 && !value) {
    return (
      <p className="text-[13px] text-muted-foreground">
        {resourceType === 'bucket'
          ? t('This project has no buckets yet.')
          : t('This project has no databases of this type yet.')}
      </p>
    )
  }

  const options =
    items.some((item) => item.$id === value) || !value
      ? items
      : [{ $id: value, name: value }, ...items]

  return (
    <Select
      value={value}
      onValueChange={(next) => {
        if (next) onChange(next)
      }}
      disabled={disabled}
    >
      <SelectTrigger className="w-full">
        <SelectValue
          placeholder={query.isLoading ? t('Loading...') : t('Select resource')}
        />
      </SelectTrigger>
      <SelectContent>
        {options.map((item) => (
          <SelectItem key={item.$id} value={item.$id}>
            <span className="truncate">{item.name || item.$id}</span>
            <span className="ms-2 font-mono text-[11px] text-muted-foreground">
              {item.$id}
            </span>
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}

interface RuleFieldsProps {
  projectId: string
  form: RuleForm
  onChange: (changes: Partial<RuleForm>) => void
  disabled?: boolean
}

export function RuleFields({
  projectId,
  form,
  onChange,
  disabled,
}: RuleFieldsProps) {
  const t = useT()
  const { isCloud } = useConsoleProfile()
  const definition = METRICS[form.metric]
  const metrics = metricsFor(form.resourceType)

  const setResourceType = (resourceType: AlertResourceType) => {
    const available = metricsFor(resourceType)
    onChange({
      resourceType,
      resourceId: '',
      metric: available.includes(form.metric) ? form.metric : available[0],
    })
  }

  const toggleChannel = (channel: AlertChannel, checked: boolean) => {
    onChange({
      channels: checked
        ? [...form.channels, channel]
        : form.channels.filter((entry) => entry !== channel),
    })
  }

  const thresholdHint =
    definition.unit === 'percent'
      ? t('Percent, between 0 and 100.')
      : definition.unit === 'count'
        ? definition.counter
          ? t('Total operations within the window.')
          : t('Latest reading.')
        : definition.counter
          ? t('Total transferred within the window.')
          : t('Latest reading.')

  return (
    <div className="space-y-5">
      <div className="space-y-2">
        <Label htmlFor="alert-name" className="text-[12px] font-medium">
          {t('Name')} <span className="text-destructive">*</span>
        </Label>
        <Input
          id="alert-name"
          placeholder={t('Egress spike')}
          value={form.name}
          onChange={(event) => onChange({ name: event.target.value })}
          disabled={disabled}
          maxLength={128}
        />
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-2">
          <Label className="text-[12px] font-medium">
            {t('Resource type')}
          </Label>
          <Select
            value={form.resourceType}
            onValueChange={(next) => {
              if (next) setResourceType(next as AlertResourceType)
            }}
            disabled={disabled}
          >
            <SelectTrigger className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {RESOURCE_TYPES.filter(
                // Dedicated databases exist only on the cloud profile.
                (type) => isCloud || type.value !== 'dedicatedDatabase',
              ).map((type) => (
                <SelectItem key={type.value} value={type.value}>
                  {t(type.label)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-2">
          <Label className="text-[12px] font-medium">{t('Metric')}</Label>
          <Select
            value={form.metric}
            onValueChange={(next) => {
              // Radix reports '' when the selected item leaves the list on a resource type change.
              if (next in METRICS) onChange({ metric: next as AlertMetric })
            }}
            disabled={disabled}
          >
            <SelectTrigger className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {metrics.map((metric) => (
                <SelectItem key={metric} value={metric}>
                  {t(METRICS[metric].label)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      {form.resourceType !== 'project' && (
        <div className="space-y-2">
          <Label className="text-[12px] font-medium">
            {t('Resource')} <span className="text-destructive">*</span>
          </Label>
          <ResourcePicker
            projectId={projectId}
            resourceType={form.resourceType}
            value={form.resourceId}
            onChange={(resourceId) => onChange({ resourceId })}
            disabled={disabled}
          />
        </div>
      )}

      <div className="space-y-2">
        <Label htmlFor="alert-threshold" className="text-[12px] font-medium">
          {t('Condition')}
        </Label>
        <div className="flex flex-col gap-2 sm:flex-row">
          <Select
            value={form.comparator}
            onValueChange={(next) =>
              onChange({ comparator: next as AlertComparator })
            }
            disabled={disabled}
          >
            <SelectTrigger className="w-full sm:w-56">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {COMPARATORS.map((comparator) => (
                <SelectItem key={comparator.value} value={comparator.value}>
                  <span className="w-4 font-mono">{comparator.symbol}</span>
                  {t(comparator.label)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <div className="flex flex-1 gap-2">
            <NumberInput
              id="alert-threshold"
              className="flex-1"
              min={0}
              max={definition.unit === 'percent' ? 100 : undefined}
              step="any"
              value={form.threshold}
              onValueChange={(threshold) => onChange({ threshold })}
              disabled={disabled}
            />
            {definition.unit === 'bytes' ? (
              <Select
                value={form.byteUnit}
                onValueChange={(next) =>
                  onChange({ byteUnit: next as ByteUnit })
                }
                disabled={disabled}
              >
                <SelectTrigger className="w-24">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {BYTE_UNITS.map((unit) => (
                    <SelectItem key={unit.value} value={unit.value}>
                      {unit.value}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            ) : (
              <span className="flex h-9 min-w-12 items-center justify-center rounded-md border border-border bg-muted/40 px-3 text-[13px] text-muted-foreground">
                {definition.unit === 'percent'
                  ? '%'
                  : (definition.suffix ?? '#')}
              </span>
            )}
          </div>
        </div>
        <p className="text-[12px] text-muted-foreground">{thresholdHint}</p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        {definition.counter && (
          <div className="space-y-2">
            <Label className="text-[12px] font-medium">{t('Window')}</Label>
            <Select
              value={String(form.window)}
              onValueChange={(next) => onChange({ window: Number(next) })}
              disabled={disabled}
            >
              <SelectTrigger className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {durationOptions(WINDOWS, form.window, t).map((option) => (
                  <SelectItem key={option.value} value={String(option.value)}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}
        <div className="space-y-2">
          <Label className="text-[12px] font-medium">{t('Cooldown')}</Label>
          <Select
            value={String(form.cooldown)}
            onValueChange={(next) => onChange({ cooldown: Number(next) })}
            disabled={disabled}
          >
            <SelectTrigger className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {durationOptions(COOLDOWNS, form.cooldown, t).map((option) => (
                <SelectItem key={option.value} value={String(option.value)}>
                  {option.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      <div className="space-y-2">
        <Label className="text-[12px] font-medium">
          {t('Notify via')} <span className="text-destructive">*</span>
        </Label>
        <div className="flex flex-wrap gap-x-5 gap-y-2">
          {CHANNELS.map((channel) => (
            <div key={channel.value} className="flex items-center gap-2">
              <Checkbox
                id={`alert-channel-${channel.value}`}
                checked={form.channels.includes(channel.value)}
                onCheckedChange={(checked) =>
                  toggleChannel(channel.value, checked === true)
                }
                disabled={disabled}
              />
              <Label
                htmlFor={`alert-channel-${channel.value}`}
                className="text-[13px] font-normal leading-none"
              >
                {t(channel.label)}
              </Label>
            </div>
          ))}
        </div>
      </div>

      {form.channels.includes('webhook') && (
        <div className="space-y-2">
          <Label
            htmlFor="alert-webhook-url"
            className="text-[12px] font-medium"
          >
            {t('Webhook URL')} <span className="text-destructive">*</span>
          </Label>
          <Input
            id="alert-webhook-url"
            type="url"
            placeholder="https://example.com/alerts"
            value={form.webhookUrl}
            onChange={(event) => onChange({ webhookUrl: event.target.value })}
            disabled={disabled}
          />
          <p className="text-[12px] text-muted-foreground">
            {t(
              'Appwrite sends a signed POST request when the rule fires and when it resolves.',
            )}
          </p>
        </div>
      )}
    </div>
  )
}
