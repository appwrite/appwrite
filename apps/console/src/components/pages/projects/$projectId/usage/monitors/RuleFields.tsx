import { useState, type ComponentType, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Bell, Check, ChevronDown, Mail, Webhook } from 'lucide-react'
import { MonitorChannel } from '@appwrite.io/console'
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
import {
  buildRules,
  type AlertComparator,
  type AlertMetric,
  type AlertResourceType,
  type MonitorInput,
  type ParsedMonitor,
} from '@/lib/appwrite/monitors'
import {
  BYTE_UNITS,
  COMPARATORS,
  COOLDOWNS,
  METRICS,
  RESOURCE_TYPES,
  WINDOWS,
  fromBytes,
  toBytes,
  type ByteUnit,
} from '@/lib/alerts/metrics'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { RuleChart } from './RuleChart'

/** Monitor form state; `threshold` is in `byteUnit` for byte metrics. */
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
  channels: MonitorChannel[]
  webhookUrl: string
}

const RESOURCE_PICKER_LIMIT = 100

const CHANNELS: {
  value: MonitorChannel
  label: string
  description: string
  icon: ComponentType<{ className?: string }>
}[] = [
  {
    value: MonitorChannel.Email,
    label: 'Email',
    description: 'Organization owners',
    icon: Mail,
  },
  {
    value: MonitorChannel.Console,
    label: 'Console',
    description: 'Notification bell',
    icon: Bell,
  },
  {
    value: MonitorChannel.Webhook,
    label: 'Webhook',
    description: 'Signed POST request',
    icon: Webhook,
  },
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
    channels: [MonitorChannel.Email, MonitorChannel.Console],
    webhookUrl: '',
  }
}

export function ruleFormFromMonitor(rule: ParsedMonitor): RuleForm {
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

/** Name a monitor gets when none is typed, e.g. `Outbound bandwidth > 10 GB`. */
export function suggestedName(
  form: RuleForm,
  t: (text: string) => string,
): string {
  const definition = METRICS[form.metric]
  const symbol =
    COMPARATORS.find((entry) => entry.value === form.comparator)?.symbol ?? ''
  const unit =
    definition.unit === 'bytes'
      ? ` ${form.byteUnit}`
      : definition.unit === 'percent'
        ? '%'
        : definition.suffix
          ? ` ${definition.suffix}`
          : ''
  return `${t(definition.label)} ${symbol} ${form.threshold.toLocaleString('en-US')}${unit}`
}

/** The form's threshold in the metric's unit. */
export function formThreshold(form: RuleForm): number {
  return METRICS[form.metric]?.unit === 'bytes'
    ? toBytes(form.threshold, form.byteUnit)
    : form.threshold
}

/** The database or bucket the form watches; empty for the whole project. */
export function formResourceId(form: RuleForm): string {
  return form.resourceType === 'project' ? '' : form.resourceId.trim()
}

/** API input for a form: its condition as rules, the threshold in the metric's unit. */
export function monitorInputFromForm(
  form: RuleForm,
  t: (text: string) => string,
): MonitorInput {
  const webhook = form.channels.includes(MonitorChannel.Webhook)
  return {
    name: form.name.trim() || suggestedName(form, t),
    rules: buildRules({
      metric: form.metric,
      comparator: form.comparator,
      threshold: formThreshold(form),
      resourceType: form.resourceType,
      resourceId: formResourceId(form),
    }),
    // Gauges compare their latest reading, so the API ignores a window for them.
    window: METRICS[form.metric]?.counter ? form.window : undefined,
    cooldown: form.cooldown,
    channels: form.channels,
    // The API validates any URL it is sent, so an empty one is left out.
    webhookUrl: webhook ? form.webhookUrl.trim() : undefined,
  }
}

/** Why the form cannot be submitted, or undefined when it can. */
export function ruleFormError(
  form: RuleForm,
  t: (text: string) => string,
): string | undefined {
  if (form.resourceType !== 'project' && !form.resourceId.trim()) {
    return t('Select the resource to watch.')
  }
  if (!Number.isFinite(form.threshold) || form.threshold < 0) {
    return t('Enter a threshold of 0 or more.')
  }
  if (form.channels.length === 0) return t('Select at least one channel.')
  if (
    form.channels.includes(MonitorChannel.Webhook) &&
    !form.webhookUrl.trim()
  ) {
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

/** A muted word between the controls of the condition sentence. */
function Word({ children }: { children: ReactNode }) {
  return <span className="text-[13px] text-muted-foreground">{children}</span>
}

function SectionHeading({
  title,
  description,
}: {
  title: string
  description?: string
}) {
  return (
    <div className="mb-3">
      <h3 className="text-[13px] font-semibold text-foreground">{title}</h3>
      {description && (
        <p className="mt-0.5 text-[12px] text-muted-foreground">
          {description}
        </p>
      )}
    </div>
  )
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
  // Dedicated databases exist only on the cloud profile.
  const metrics = (Object.keys(METRICS) as AlertMetric[]).filter(
    (metric) =>
      isCloud || !METRICS[metric].resourceTypes.includes('dedicatedDatabase'),
  )
  const resourceTypes = RESOURCE_TYPES.filter((type) =>
    definition.resourceTypes.includes(type.value),
  )

  const setMetric = (metric: AlertMetric) => {
    const types = METRICS[metric].resourceTypes
    const resourceType = types.includes(form.resourceType)
      ? form.resourceType
      : types[0]
    onChange({
      metric,
      resourceType,
      resourceId: resourceType === form.resourceType ? form.resourceId : '',
    })
  }

  const toggleChannel = (channel: MonitorChannel) => {
    onChange({
      channels: form.channels.includes(channel)
        ? form.channels.filter((entry) => entry !== channel)
        : [...form.channels, channel],
    })
  }

  // Folded unless the monitor already sets either, so a new monitor shows only what it needs.
  const [optional, setOptional] = useState(
    form.name !== '' || form.cooldown !== emptyRuleForm().cooldown,
  )

  return (
    <div className="space-y-7">
      <section>
        <SectionHeading
          title={t('Condition')}
          description={
            definition.counter
              ? t('Counted over the window and compared at every check.')
              : t('The latest reading is compared at every check.')
          }
        />
        <div className="rounded-xl border border-border bg-muted/20 p-4">
          <div className="flex flex-wrap items-center gap-2">
            <Word>{t('When')}</Word>
            <Select
              value={form.metric}
              onValueChange={(next) => {
                if (next in METRICS) setMetric(next as AlertMetric)
              }}
              disabled={disabled}
            >
              <SelectTrigger
                className="h-9 w-auto min-w-44"
                aria-label={t('Metric')}
              >
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
            <Word>{t('on')}</Word>
            {resourceTypes.length > 1 ? (
              <Select
                value={form.resourceType}
                onValueChange={(next) => {
                  if (next) {
                    onChange({
                      resourceType: next as AlertResourceType,
                      resourceId: '',
                    })
                  }
                }}
                disabled={disabled}
              >
                <SelectTrigger
                  className="h-9 w-auto min-w-36"
                  aria-label={t('Resource type')}
                >
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {resourceTypes.map((type) => (
                    <SelectItem key={type.value} value={type.value}>
                      {type.value === 'project'
                        ? t('The whole project')
                        : t(type.label)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            ) : (
              <span className="text-[13px] font-medium text-foreground">
                {form.resourceType === 'project'
                  ? t('the whole project')
                  : t(
                      RESOURCE_TYPES.find(
                        (type) => type.value === form.resourceType,
                      )?.label ?? '',
                    )}
              </span>
            )}
            {form.resourceType !== 'project' && (
              <div className="min-w-48 flex-1">
                <ResourcePicker
                  projectId={projectId}
                  resourceType={form.resourceType}
                  value={form.resourceId}
                  onChange={(resourceId) => onChange({ resourceId })}
                  disabled={disabled}
                />
              </div>
            )}
          </div>

          <div className="mt-3 flex flex-wrap items-center gap-2">
            <Word>{t('is')}</Word>
            <Select
              value={form.comparator}
              onValueChange={(next) =>
                onChange({ comparator: next as AlertComparator })
              }
              disabled={disabled}
            >
              <SelectTrigger
                className="h-9 w-auto min-w-40"
                aria-label={t('Comparator')}
              >
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {COMPARATORS.map((comparator) => (
                  <SelectItem key={comparator.value} value={comparator.value}>
                    {t(comparator.label).toLowerCase()}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <div className="flex items-center gap-1.5">
              <NumberInput
                id="alert-threshold"
                aria-label={t('Threshold')}
                className="h-9 w-28"
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
                  <SelectTrigger className="h-9 w-20" aria-label={t('Unit')}>
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
                <Word>
                  {definition.unit === 'percent'
                    ? '%'
                    : t(definition.suffix ?? 'operations')}
                </Word>
              )}
            </div>
            {definition.counter && (
              <>
                <Word>{t('within')}</Word>
                <Select
                  value={String(form.window)}
                  onValueChange={(next) => onChange({ window: Number(next) })}
                  disabled={disabled}
                >
                  <SelectTrigger
                    className="h-9 w-auto min-w-32"
                    aria-label={t('Window')}
                  >
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {durationOptions(WINDOWS, form.window, t).map((option) => (
                      <SelectItem
                        key={option.value}
                        value={String(option.value)}
                      >
                        {option.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </>
            )}
          </div>

          <div className="mt-4 border-t border-border pt-4">
            <RuleChart
              projectId={projectId}
              rule={{
                metric: form.metric,
                resourceType: form.resourceType,
                resourceId: formResourceId(form),
                window: form.window,
                comparator: form.comparator,
                threshold: formThreshold(form),
              }}
            />
          </div>
        </div>
      </section>

      <section>
        <SectionHeading
          title={t('Notify')}
          description={t(
            'Sent when the monitor fires and again when it resolves.',
          )}
        />
        <div className="grid gap-2 sm:grid-cols-3">
          {CHANNELS.map((channel) => {
            const selected = form.channels.includes(channel.value)
            const Icon = channel.icon
            return (
              <button
                key={channel.value}
                type="button"
                role="checkbox"
                aria-checked={selected}
                disabled={disabled}
                onClick={() => toggleChannel(channel.value)}
                className={cn(
                  'flex items-start gap-2.5 rounded-lg border px-3 py-2.5 text-start transition-colors disabled:cursor-not-allowed disabled:opacity-60',
                  selected
                    ? 'border-foreground ring-1 ring-inset ring-foreground'
                    : 'border-border hover:bg-accent/40',
                )}
              >
                <span
                  aria-hidden
                  className={cn(
                    'mt-0.5 flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded-[4px] border-[1.5px]',
                    selected
                      ? 'border-foreground bg-foreground text-background'
                      : 'border-muted-foreground/60',
                  )}
                >
                  {selected && (
                    <Check className="h-2.5 w-2.5" strokeWidth={3} />
                  )}
                </span>
                <span>
                  <span className="flex items-center gap-1.5 text-[13px] font-medium text-foreground">
                    <Icon className="h-3.5 w-3.5 text-muted-foreground" />
                    {t(channel.label)}
                  </span>
                  <span className="block text-[12px] text-muted-foreground">
                    {t(channel.description)}
                  </span>
                </span>
              </button>
            )
          })}
        </div>

        {form.channels.includes(MonitorChannel.Webhook) && (
          <div className="mt-4 space-y-2">
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
          </div>
        )}
      </section>

      <section className="border-t border-border pt-4">
        <button
          type="button"
          onClick={() => setOptional((open) => !open)}
          aria-expanded={optional}
          className="flex w-full items-center justify-between text-[13px] text-muted-foreground hover:text-foreground"
        >
          <span>{t('Name, cooldown')}</span>
          <span className="inline-flex items-center gap-1">
            {t('Optional')}
            <ChevronDown
              className={cn(
                'h-4 w-4 transition-transform',
                optional && 'rotate-180',
              )}
            />
          </span>
        </button>
        {optional && (
          <div className="mt-4 space-y-4">
            <div>
              <Label htmlFor="alert-name" className="text-[13px] font-semibold">
                {t('Name')}
              </Label>
              <Input
                id="alert-name"
                className="mt-2"
                placeholder={suggestedName(form, t)}
                value={form.name}
                onChange={(event) => onChange({ name: event.target.value })}
                disabled={disabled}
                maxLength={128}
              />
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <Word>{t('Cooldown')}</Word>
              <Select
                value={String(form.cooldown)}
                onValueChange={(next) => onChange({ cooldown: Number(next) })}
                disabled={disabled}
              >
                <SelectTrigger
                  className="h-9 w-auto min-w-32"
                  aria-label={t('Cooldown')}
                >
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {durationOptions(COOLDOWNS, form.cooldown, t).map(
                    (option) => (
                      <SelectItem
                        key={option.value}
                        value={String(option.value)}
                      >
                        {option.label}
                      </SelectItem>
                    ),
                  )}
                </SelectContent>
              </Select>
              <Word>{t('between two alerts from this monitor.')}</Word>
            </div>
          </div>
        )}
      </section>
    </div>
  )
}
