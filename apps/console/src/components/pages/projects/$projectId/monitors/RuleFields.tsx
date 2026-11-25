import { useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import * as RadioGroupPrimitive from '@radix-ui/react-radio-group'
import {
  BellRing,
  ChevronDown,
  CircleX,
  Info,
  TriangleAlert,
  type LucideIcon,
} from 'lucide-react'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { NumberInput } from '@/components/ui/number-input'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  bucketsQueryOptions,
  databasesQueryOptions,
  dedicatedDatabasesQueryOptions,
  functionsQueryOptions,
  sitesQueryOptions,
  useEscalationMembers,
  useEscalations,
  useProject,
} from '@/lib/react-query/hooks'
import { escalationSummary } from '@/lib/alerts/escalation'
import {
  buildRules,
  type AlertComparator,
  type AlertMetric,
  type AlertResourceType,
  type MonitorInput,
  type MonitorSeverity,
  type ParsedMonitor,
} from '@/lib/appwrite/monitors'
import {
  BYTE_UNITS,
  COMPARATORS,
  COOLDOWNS,
  MB_SECONDS_PER_GB_HOUR,
  METRIC_CATEGORIES,
  METRIC_NAMES,
  METRICS,
  RESOURCE_TYPES,
  WINDOWS,
  fromBytes,
  metricDefinition,
  toBytes,
  type ByteUnit,
} from '@/lib/alerts/metrics'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { RecipientsField } from '@/components/global/shared/RecipientsField'
import { RuleChart } from './RuleChart'

/** Monitor form state; `threshold` is in `byteUnit` for byte metrics and in GB-hours for MB-seconds. */
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
  notify: string[]
  /** Notify the roles once, or step by step with an escalation. */
  recipients: Recipients
  escalationId: string
  severity: MonitorSeverity
}

export type Recipients = 'roles' | 'escalation'

const RESOURCE_PICKER_LIMIT = 100

export function emptyRuleForm(): RuleForm {
  return {
    name: '',
    resourceType: 'project',
    resourceId: '',
    metric: 'network.outbound',
    comparator: 'greaterThan',
    threshold: 10,
    byteUnit: 'GB',
    window: 3600,
    cooldown: 3600,
    notify: ['owner'],
    recipients: 'roles',
    escalationId: '',
    severity: 'warning',
  }
}

export function ruleFormFromMonitor(rule: ParsedMonitor): RuleForm {
  const unit = metricDefinition(rule.metric).unit
  const split = unit === 'bytes' ? fromBytes(rule.threshold) : null
  return {
    name: rule.name,
    resourceType: rule.resourceType,
    resourceId: rule.resourceType === 'project' ? '' : rule.resourceId,
    metric: rule.metric,
    comparator: rule.comparator,
    threshold: split
      ? split.amount
      : unit === 'gbHours'
        ? rule.threshold / MB_SECONDS_PER_GB_HOUR
        : rule.threshold,
    byteUnit: split ? split.unit : 'GB',
    window: rule.window,
    cooldown: rule.cooldown,
    notify: rule.notify,
    recipients: rule.escalationId ? 'escalation' : 'roles',
    escalationId: rule.escalationId ?? '',
    severity: rule.severity,
  }
}

/**
 * The levels a monitor can take, least urgent first, with what each one changes. The
 * colors follow the console's status badges, so a level reads the same everywhere.
 */
export const SEVERITIES: {
  value: MonitorSeverity
  label: string
  /** When to pick it. */
  hint: string
  /** How its alerts are delivered differently from the other levels. */
  delivery?: string
  icon: LucideIcon
  /** The icon's color, always shown. */
  tone: string
}[] = [
  {
    value: 'info',
    label: 'Info',
    hint: 'Worth knowing about. Nothing needs doing yet.',
    icon: Info,
    tone: 'text-blue-600 dark:text-blue-400',
  },
  {
    value: 'warning',
    label: 'Warning',
    hint: 'Look into it soon, before it becomes a problem.',
    icon: TriangleAlert,
    tone: 'text-amber-600 dark:text-amber-400',
  },
  {
    value: 'error',
    label: 'Error',
    hint: 'Something is broken and needs fixing.',
    icon: CircleX,
    tone: 'text-orange-600 dark:text-orange-400',
  },
  {
    value: 'critical',
    label: 'Critical',
    hint: 'Someone needs to act now, even at night.',
    delivery: 'The push plays a siren through silent mode and Do Not Disturb.',
    icon: BellRing,
    tone: 'text-red-600 dark:text-red-400',
  },
]

/** Organization roles the form offers to notify; the server validates `notify` against its own list. */
export const NOTIFY_ROLES = ['owner', 'developer']

/** How the form and the list name a role a monitor notifies. */
export function roleLabel(role: string): string {
  const labels: Record<string, string> = {
    owner: 'Owners',
    developer: 'Developers',
    editor: 'Editors',
    analyst: 'Analysts',
    billing: 'Billing',
  }
  return labels[role] ?? role
}

/** Name a monitor gets when none is typed, e.g. `Outbound bandwidth > 10 GB`. */
export function suggestedName(
  form: RuleForm,
  t: (text: string) => string,
): string {
  const definition = metricDefinition(form.metric)
  const symbol =
    COMPARATORS.find((entry) => entry.value === form.comparator)?.symbol ?? ''
  const unit =
    definition.unit === 'bytes'
      ? ` ${form.byteUnit}`
      : definition.unit === 'percent'
        ? '%'
        : definition.unit === 'gbHours'
          ? ' GB-hours'
          : definition.suffix
            ? ` ${definition.suffix}`
            : ''
  return `${t(definition.label)} ${symbol} ${form.threshold.toLocaleString('en-US')}${unit}`
}

/** The form's threshold in the metric's unit. */
export function formThreshold(form: RuleForm): number {
  const unit = metricDefinition(form.metric).unit
  if (unit === 'bytes') return toBytes(form.threshold, form.byteUnit)
  if (unit === 'gbHours') {
    return Math.round(form.threshold * MB_SECONDS_PER_GB_HOUR)
  }
  return form.threshold
}

/** The resource the form watches; empty for the whole project. */
export function formResourceId(form: RuleForm): string {
  return form.resourceType === 'project' ? '' : form.resourceId.trim()
}

/** API input for a form: its condition as rules, the threshold in the metric's unit. */
export function monitorInputFromForm(
  form: RuleForm,
  t: (text: string) => string,
): MonitorInput {
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
    window: metricDefinition(form.metric).counter ? form.window : undefined,
    cooldown: form.cooldown,
    notify: form.notify,
    escalationId: form.recipients === 'escalation' ? form.escalationId : '',
    severity: form.severity,
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
  if (form.recipients === 'escalation' && !form.escalationId) {
    return t('Select an escalation.')
  }
  return undefined
}

export function durationOptions(
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
  const functions = useQuery({
    ...functionsQueryOptions(projectId, 0, RESOURCE_PICKER_LIMIT),
    enabled: resourceType === 'function',
  })
  const sites = useQuery({
    ...sitesQueryOptions(projectId, 0, RESOURCE_PICKER_LIMIT),
    enabled: resourceType === 'site',
  })
  const dedicated = useQuery({
    ...dedicatedDatabasesQueryOptions(projectId),
    enabled: resourceType === 'dedicatedDatabase',
  })

  const sources = {
    database: { query: databases, items: databases.data?.databases },
    bucket: { query: buckets, items: buckets.data?.buckets },
    function: { query: functions, items: functions.data?.functions },
    site: { query: sites, items: sites.data?.sites },
    dedicatedDatabase: { query: dedicated, items: dedicated.data?.databases },
  }
  const source =
    resourceType in sources
      ? sources[resourceType as keyof typeof sources]
      : sources.dedicatedDatabase
  const query = source.query
  const items: { $id: string; name: string }[] = source.items ?? []

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
          : resourceType === 'function'
            ? t('This project has no functions yet.')
            : resourceType === 'site'
              ? t('This project has no sites yet.')
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
  /** Sets the threshold to half the highest value charted until it is edited. */
  suggestThreshold?: boolean
}

/** Half of a charted peak as a form threshold, rounded to two significant digits. */
export function thresholdFromPeak(
  metric: AlertMetric,
  peak: number,
): Pick<RuleForm, 'threshold'> & { byteUnit?: ByteUnit } {
  return thresholdFromValue(metric, peak / 2)
}

/** A value in the metric's unit as a form threshold, rounded to two significant digits. */
export function thresholdFromValue(
  metric: AlertMetric,
  value: number,
): Pick<RuleForm, 'threshold'> & { byteUnit?: ByteUnit } {
  const half = value
  const round = (value: number) => {
    if (value <= 0) return 0
    const step = 10 ** (Math.floor(Math.log10(value)) - 1)
    return parseFloat((Math.round(value / step) * step).toFixed(6))
  }
  const unit = metricDefinition(metric).unit
  if (unit === 'bytes') {
    const scale =
      [...BYTE_UNITS].reverse().find((entry) => half >= entry.bytes) ??
      BYTE_UNITS[0]
    return { threshold: round(half / scale.bytes), byteUnit: scale.value }
  }
  if (unit === 'gbHours') {
    return { threshold: round(half / MB_SECONDS_PER_GB_HOUR) }
  }
  if (unit === 'percent') return { threshold: Math.min(100, round(half)) }
  return { threshold: Math.max(1, Math.round(round(half))) }
}

/** A labeled control in the condition grid. */
function Field({
  label,
  className,
  children,
}: {
  label: string
  className?: string
  children: ReactNode
}) {
  return (
    <div className={cn('min-w-0 space-y-1.5', className)}>
      <span className="block text-[12px] font-medium text-muted-foreground">
        {label}
      </span>
      {children}
    </div>
  )
}

/** A muted word between the controls of the condition sentence. */
export function Word({ children }: { children: ReactNode }) {
  return <span className="text-[13px] text-muted-foreground">{children}</span>
}

export function SectionHeading({
  title,
  description,
}: {
  title: string
  description?: string
}) {
  return (
    <div className="mb-4">
      <h3 className="text-[13px] font-semibold text-foreground">{title}</h3>
      {description && (
        <p className="mt-0.5 text-[12px] text-muted-foreground">
          {description}
        </p>
      )}
    </div>
  )
}

/** Every field of a usage monitor: its condition, who it notifies, and its name and cooldown. */
export function RuleFields(props: RuleFieldsProps) {
  const t = useT()
  const { projectId, form, onChange, disabled } = props
  const { project } = useProject(projectId)
  return (
    <div className="space-y-8">
      <ConditionFields {...props} />

      <NotifyFields
        notify={form.notify}
        severity={form.severity}
        escalation={{
          organizationId: project?.teamId,
          recipients: form.recipients,
          escalationId: form.escalationId,
        }}
        onChange={onChange}
        disabled={disabled}
      />

      <OptionalFields
        name={form.name}
        cooldown={form.cooldown}
        placeholder={suggestedName(form, t)}
        onChange={onChange}
        disabled={disabled}
      />
    </div>
  )
}

/** What the monitor measures and when it fires, over a chart of that measure. */
export function ConditionFields({
  projectId,
  form,
  onChange,
  disabled,
  suggestThreshold = false,
  bare = false,
}: RuleFieldsProps & {
  /** Leaves out the heading, for a card that has its own. */
  bare?: boolean
}) {
  const t = useT()
  const [suggesting, setSuggesting] = useState(suggestThreshold)
  // The series the last suggestion came from, so each one suggests once.
  const [suggested, setSuggested] = useState('')
  const seriesKey = [
    form.metric,
    form.resourceType,
    form.resourceId,
    form.window,
  ].join('|')
  const suggest = (peak: number) => {
    if (suggested === seriesKey || peak <= 0) return
    setSuggested(seriesKey)
    onChange(thresholdFromPeak(form.metric, peak))
  }
  const { isCloud } = useConsoleProfile()
  const definition = metricDefinition(form.metric)
  // Dedicated databases and analytics exist only on the cloud profile.
  const metrics = METRIC_NAMES.filter(
    (metric) =>
      isCloud ||
      (!metricDefinition(metric).resourceTypes.includes('dedicatedDatabase') &&
        metricDefinition(metric).category !== 'analytics'),
  )
  const groups = METRIC_CATEGORIES.map((category) => ({
    ...category,
    metrics: metrics.filter(
      (metric) => metricDefinition(metric).category === category.value,
    ),
  })).filter((group) => group.metrics.length > 0)
  const resourceTypes = RESOURCE_TYPES.filter((type) =>
    definition.resourceTypes.includes(type.value),
  )

  const setMetric = (metric: AlertMetric) => {
    const types = metricDefinition(metric).resourceTypes
    const resourceType = types.includes(form.resourceType)
      ? form.resourceType
      : types[0]
    onChange({
      metric,
      resourceType,
      resourceId: resourceType === form.resourceType ? form.resourceId : '',
    })
  }

  return (
    <section>
      {!bare && (
        <SectionHeading
          title={t('Condition')}
          description={
            definition.counter
              ? t('Counted over the window and compared at every check.')
              : t('The latest reading is compared at every check.')
          }
        />
      )}
      <div className="grid gap-x-4 gap-y-5 sm:grid-cols-2">
        <Field label={t('Metric')}>
          <Select
            value={form.metric}
            onValueChange={(next) => {
              if (next in METRICS) setMetric(next as AlertMetric)
            }}
            disabled={disabled}
          >
            <SelectTrigger className="h-9 w-full" aria-label={t('Metric')}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {groups.map((group) => (
                <SelectGroup key={group.value}>
                  <SelectLabel>{t(group.label)}</SelectLabel>
                  {group.metrics.map((metric) => (
                    <SelectItem key={metric} value={metric}>
                      {t(metricDefinition(metric).label)}
                    </SelectItem>
                  ))}
                </SelectGroup>
              ))}
            </SelectContent>
          </Select>
        </Field>
        <Field label={t('Applies to')}>
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
                className="h-9 w-full"
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
            <div className="flex h-9 items-center rounded-md border border-border bg-muted/40 px-3 text-[13px] text-muted-foreground">
              {form.resourceType === 'project'
                ? t('The whole project')
                : t(
                    RESOURCE_TYPES.find(
                      (type) => type.value === form.resourceType,
                    )?.label ?? '',
                  )}
            </div>
          )}
        </Field>
        {form.resourceType !== 'project' && (
          <Field label={t('Resource')} className="sm:col-span-2">
            <ResourcePicker
              projectId={projectId}
              resourceType={form.resourceType}
              value={form.resourceId}
              onChange={(resourceId) => onChange({ resourceId })}
              disabled={disabled}
            />
          </Field>
        )}
        <Field label={t('Alert when the value is')}>
          <div className="flex gap-2">
            <Select
              value={form.comparator}
              onValueChange={(next) =>
                onChange({ comparator: next as AlertComparator })
              }
              disabled={disabled}
            >
              <SelectTrigger
                className="h-9 w-auto min-w-0 shrink-0"
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
            <div className="relative min-w-0 flex-1">
              <NumberInput
                id="alert-threshold"
                aria-label={t('Threshold')}
                className={cn(
                  'h-9 w-full',
                  definition.unit !== 'bytes' && 'pe-20',
                )}
                min={0}
                max={definition.unit === 'percent' ? 100 : undefined}
                step="any"
                value={form.threshold}
                onValueChange={(threshold) => {
                  setSuggesting(false)
                  onChange({ threshold })
                }}
                disabled={disabled}
              />
              {definition.unit !== 'bytes' && (
                <span className="pointer-events-none absolute inset-y-0 end-3 flex max-w-16 items-center truncate text-[13px] text-muted-foreground">
                  {definition.unit === 'percent'
                    ? '%'
                    : definition.unit === 'gbHours'
                      ? t('GB-hours')
                      : t(definition.suffix ?? 'operations')}
                </span>
              )}
            </div>
            {definition.unit === 'bytes' && (
              <Select
                value={form.byteUnit}
                onValueChange={(next) => {
                  setSuggesting(false)
                  onChange({ byteUnit: next as ByteUnit })
                }}
                disabled={disabled}
              >
                <SelectTrigger
                  className="h-9 w-20 shrink-0"
                  aria-label={t('Unit')}
                >
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
            )}
          </div>
        </Field>
        {definition.counter && (
          <Field label={t('Counted over')}>
            <Select
              value={String(form.window)}
              onValueChange={(next) => onChange({ window: Number(next) })}
              disabled={disabled}
            >
              <SelectTrigger className="h-9 w-full" aria-label={t('Window')}>
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
          </Field>
        )}
      </div>

      <div className="mt-6">
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
          onPeak={suggesting ? suggest : undefined}
          onThresholdChange={
            disabled
              ? undefined
              : (value) => {
                  setSuggesting(false)
                  onChange(thresholdFromValue(form.metric, value))
                }
          }
        />
      </div>
    </section>
  )
}

/** Who a monitor notifies, and how urgently. */
export function NotifyFields({
  notify,
  severity,
  escalation,
  onChange,
  disabled,
  bare = false,
  recipientsOnly = false,
}: {
  /** Leaves out the heading, for a card that has its own. */
  bare?: boolean
  /** Leaves out the severity, for a card of its own. */
  recipientsOnly?: boolean
  notify: string[]
  severity: MonitorSeverity
  /** Offers an escalation instead of the roles. */
  escalation?: {
    organizationId: string | undefined
    recipients: Recipients
    escalationId: string
  }
  onChange: (changes: {
    notify?: string[]
    severity?: MonitorSeverity
    recipients?: Recipients
    escalationId?: string
  }) => void
  disabled?: boolean
}) {
  const t = useT()
  const usesEscalation = escalation?.recipients === 'escalation'
  return (
    <>
      <section>
        {!bare && (
          <SectionHeading
            title={t('Notify')}
            description={t(
              'Who gets an email, a push and a console notification when the monitor fires and when it resolves.',
            )}
          />
        )}
        <RecipientsField
          organizationId={escalation?.organizationId}
          roles={NOTIFY_ROLES}
          members={false}
          escalations={!!escalation}
          label={t('Add who gets notified')}
          value={{
            roles: notify,
            userIds: [],
            escalationId: usesEscalation ? escalation.escalationId : '',
          }}
          onChange={(next) =>
            onChange({
              notify: next.roles,
              recipients: next.escalationId ? 'escalation' : 'roles',
              escalationId: next.escalationId,
            })
          }
          disabled={disabled}
        />
        <p className="mt-3 text-[12px] text-muted-foreground">
          {usesEscalation ? (
            <EscalationSummary
              organizationId={escalation.organizationId}
              escalationId={escalation.escalationId}
            />
          ) : notify.length === 0 ? (
            t(
              'Nobody is emailed or notified. The alerts still fire their events for project webhooks and functions.',
            )
          ) : (
            t(
              'Notified once when the monitor fires and again when it resolves. Pick an escalation instead to notify step by step until someone acknowledges.',
            )
          )}
        </p>
      </section>
      {!recipientsOnly && (
        <section>
          <h3
            id="alert-severity"
            className="text-[13px] font-semibold text-foreground"
          >
            {t('Severity')}
          </h3>
          <SeverityPicker
            value={severity}
            onChange={(next) => onChange({ severity: next })}
            disabled={disabled}
          />
        </section>
      )}
    </>
  )
}

/**
 * The four levels as cards that say when to pick each and what its alerts do, so the one
 * that changes delivery, critical, reads differently from the rest.
 */
export function SeverityPicker({
  value,
  onChange,
  disabled,
}: {
  value: MonitorSeverity
  onChange: (severity: MonitorSeverity) => void
  disabled?: boolean
}) {
  const t = useT()
  const current =
    SEVERITIES.find((entry) => entry.value === value) ?? SEVERITIES[1]
  return (
    <>
      {/* Radix gives the group one tab stop and moves the selection with the arrow keys. */}
      <RadioGroupPrimitive.Root
        aria-labelledby="alert-severity"
        value={value}
        onValueChange={(next) => onChange(next as MonitorSeverity)}
        disabled={disabled}
        className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4"
      >
        {SEVERITIES.map(({ value: level, label, icon: Icon, tone }) => (
          <RadioGroupPrimitive.Item
            key={level}
            value={level}
            className={cn(
              'flex h-10 items-center justify-center gap-2 rounded-lg border text-[13px] font-medium text-foreground transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-60',
              value === level
                ? 'border-foreground ring-1 ring-inset ring-foreground'
                : 'border-border hover:bg-accent/40',
            )}
          >
            <Icon aria-hidden className={cn('h-4 w-4 shrink-0', tone)} />
            {t(label)}
          </RadioGroupPrimitive.Item>
        ))}
      </RadioGroupPrimitive.Root>
      {/* What the chosen level means, in one line under the choice. */}
      <p aria-live="polite" className="mt-3 text-[13px] text-foreground">
        {t(current.hint)}
        {current.delivery && (
          <span className="text-muted-foreground"> {t(current.delivery)}</span>
        )}
      </p>
      <p className="mt-3 text-[12px] text-muted-foreground">
        {t(
          'Every level is emailed, shown in the console and pushed to phones, with the level in the title. Webhooks and functions receive it with the alert events. A critical push rings only on phones that allowed critical alerts.',
        )}
      </p>
    </>
  )
}

/** What the chosen escalation does, with a way to change escalations. */
function EscalationSummary({
  organizationId,
  escalationId,
}: {
  organizationId: string | undefined
  escalationId: string
}) {
  const t = useT()
  const { escalations } = useEscalations(organizationId)
  const { memberName } = useEscalationMembers(organizationId)
  const selected = escalations.find(
    (escalation) => escalation.$id === escalationId,
  )
  return (
    <>
      {selected && (
        <>
          {escalationSummary(
            selected.steps,
            memberName,
            (role) => t(roleLabel(role)),
            t,
          )}
          {selected.repeat > 0 &&
            ` · ${t('repeats {count}×').replace('{count}', String(selected.repeat))}`}
          {' · '}
        </>
      )}
      {organizationId && (
        <Link
          to="/organizations/$orgId/settings/escalations"
          params={{ orgId: organizationId }}
          className="text-foreground underline underline-offset-2"
        >
          {t('Manage escalations')}
        </Link>
      )}
    </>
  )
}

/** A monitor's name and cooldown, folded unless it already sets either. */
export function OptionalFields({
  name,
  cooldown,
  placeholder,
  onChange,
  disabled,
}: {
  name: string
  cooldown: number
  /** The name the monitor gets when none is typed. */
  placeholder: string
  onChange: (changes: { name?: string; cooldown?: number }) => void
  disabled?: boolean
}) {
  const t = useT()
  // Folded unless the monitor already sets either, so a new monitor shows only what it needs.
  const [optional, setOptional] = useState(
    name !== '' || cooldown !== emptyRuleForm().cooldown,
  )
  return (
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
              placeholder={placeholder}
              value={name}
              onChange={(event) => onChange({ name: event.target.value })}
              disabled={disabled}
              maxLength={128}
            />
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Word>{t('Cooldown')}</Word>
            <Select
              value={String(cooldown)}
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
                {durationOptions(COOLDOWNS, cooldown, t).map((option) => (
                  <SelectItem key={option.value} value={String(option.value)}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Word>{t('between two alerts from this monitor.')}</Word>
          </div>
        </div>
      )}
    </section>
  )
}
