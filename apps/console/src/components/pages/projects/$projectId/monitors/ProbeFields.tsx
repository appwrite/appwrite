import { useEffect, type ReactNode } from 'react'
import * as RadioGroupPrimitive from '@radix-ui/react-radio-group'
import {
  Gauge,
  Globe,
  Mail,
  Plug,
  Signpost,
  type LucideIcon,
} from 'lucide-react'
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
import { Textarea } from '@/components/ui/textarea'
import type {
  DnsRecord,
  HttpMethod,
  MonitorSeverity,
  ParsedMonitor,
  ProbeMonitorInput,
  ProbeType,
  SmtpTlsMode,
} from '@/lib/appwrite/monitors'
import {
  DNS_RECORDS,
  HTTP_METHODS,
  INTERVALS,
  PROBE_TYPES,
  TIMEOUTS,
  TLS_MODES,
  formatHeaders,
  parseHeaders,
  parseStatusCodes,
  probeTarget,
} from '@/lib/alerts/probes'
import { useT } from '@/lib/i18n/translate'
import { alertingLimits, defaultProbeInterval } from '@/lib/alerts/plan-limits'
import { useOrganizationPlan, useProject } from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import {
  NotifyFields,
  OptionalFields,
  SectionHeading,
  Word,
  durationOptions,
  type Recipients,
} from './RuleFields'

/** Probe monitor form state; headers and status codes are kept as typed. */
export type ProbeForm = {
  type: ProbeType
  name: string
  url: string
  method: HttpMethod
  /** `Name: value`, one header per line. */
  headers: string
  body: string
  /** Comma-separated; empty accepts any 2xx status. */
  statusCodes: string
  certificateDays: number
  domain: string
  record: DnsRecord
  resolver: string
  answer: string
  host: string
  port: number
  tlsMode: SmtpTlsMode
  interval: number
  timeout: number
  cooldown: number
  notify: string[]
  recipients: Recipients
  escalationId: string
  severity: MonitorSeverity
}

/** Methods that send no request body. */
const BODILESS_METHODS: HttpMethod[] = ['GET', 'HEAD']

export function emptyProbeForm(type: ProbeType): ProbeForm {
  return {
    type,
    name: '',
    url: '',
    method: 'GET',
    headers: '',
    body: '',
    statusCodes: '',
    certificateDays: 14,
    domain: '',
    record: 'A',
    resolver: '',
    answer: '',
    host: '',
    port: type === 'smtp' ? 587 : 443,
    tlsMode: '',
    interval: 60,
    timeout: 10,
    cooldown: 3600,
    notify: ['owner'],
    recipients: 'roles',
    escalationId: '',
    severity: 'warning',
  }
}

export function probeFormFromMonitor(
  monitor: ParsedMonitor & { type: ProbeType },
): ProbeForm {
  return {
    ...emptyProbeForm(monitor.type),
    name: monitor.name,
    url: monitor.url,
    method: (monitor.method || 'GET') as HttpMethod,
    headers: formatHeaders(monitor.headers),
    body: monitor.body,
    statusCodes: (monitor.statusCodes ?? []).join(', '),
    certificateDays: monitor.certificateDays,
    domain: monitor.domain,
    record: (monitor.record || 'A') as DnsRecord,
    resolver: monitor.resolver,
    answer: monitor.answer,
    host: monitor.host,
    port: monitor.port,
    tlsMode: monitor.tlsMode as SmtpTlsMode,
    interval: monitor.interval,
    timeout: monitor.timeout,
    cooldown: monitor.cooldown,
    notify: monitor.notify,
    recipients: monitor.escalationId ? 'escalation' : 'roles',
    escalationId: monitor.escalationId ?? '',
    severity: monitor.severity,
  }
}

/** Name a probe monitor gets when none is typed: its target, as the list shows it. */
export function suggestedProbeName(form: ProbeForm): string {
  return probeTarget({
    ...form,
    url: form.url.trim(),
    domain: form.domain.trim(),
    host: form.host.trim(),
  })
}

/** API input for a form: its type, and only the fields that type takes. */
export function probeInputFromForm(form: ProbeForm): ProbeMonitorInput {
  const settings = {
    name: form.name.trim() || suggestedProbeName(form),
    interval: form.interval,
    timeout: form.timeout,
    cooldown: form.cooldown,
    notify: form.notify,
    escalationId: form.recipients === 'escalation' ? form.escalationId : '',
    severity: form.severity,
  }
  switch (form.type) {
    case 'http':
      return {
        type: 'http',
        params: {
          ...settings,
          url: form.url.trim(),
          method: form.method,
          headers: parseHeaders(form.headers) ?? {},
          body: BODILESS_METHODS.includes(form.method) ? '' : form.body,
          statusCodes: parseStatusCodes(form.statusCodes) ?? [],
          certificateDays: form.certificateDays,
        },
      }
    case 'dns':
      return {
        type: 'dns',
        params: {
          ...settings,
          domain: form.domain.trim(),
          record: form.record,
          resolver: form.resolver.trim(),
          answer: form.answer.trim(),
        },
      }
    case 'tcp':
      return {
        type: 'tcp',
        params: { ...settings, host: form.host.trim(), port: form.port },
      }
    case 'smtp':
      return {
        type: 'smtp',
        params: {
          ...settings,
          host: form.host.trim(),
          port: form.port,
          tlsMode: form.tlsMode,
        },
      }
  }
}

/** Why the form cannot be submitted, or undefined when it can. */
export function probeFormError(
  form: ProbeForm,
  t: (text: string) => string,
): string | undefined {
  if (form.type === 'http') {
    let protocol = ''
    try {
      protocol = new URL(form.url.trim()).protocol
    } catch {
      protocol = ''
    }
    if (protocol !== 'http:' && protocol !== 'https:') {
      return t('Enter a URL starting with http:// or https://.')
    }
    if (parseHeaders(form.headers) === null) {
      return t('Write each header on its own line as Name: value.')
    }
    if (parseStatusCodes(form.statusCodes) === null) {
      return t('Enter status codes between 100 and 599.')
    }
  }
  if (form.type === 'dns' && !form.domain.trim()) {
    return t('Enter the domain to query.')
  }
  if (form.type === 'tcp' || form.type === 'smtp') {
    if (!form.host.trim()) return t('Enter the host to connect to.')
    if (!Number.isInteger(form.port) || form.port < 1 || form.port > 65535) {
      return t('Enter a port between 1 and 65535.')
    }
  }
  if (form.timeout > form.interval) {
    return t('The timeout must not be longer than the interval.')
  }
  if (form.recipients === 'escalation' && !form.escalationId) {
    return t('Select an escalation.')
  }
  return undefined
}

/** Monitor types the create form offers, usage first. */
export const MONITOR_TYPES: {
  value: 'usage' | ProbeType
  label: string
  icon: LucideIcon
}[] = [
  { value: 'usage', label: 'Usage', icon: Gauge },
  { value: 'http', label: 'HTTP', icon: Globe },
  { value: 'dns', label: 'DNS', icon: Signpost },
  { value: 'tcp', label: 'TCP', icon: Plug },
  { value: 'smtp', label: 'SMTP', icon: Mail },
]

/** Picks what a new monitor checks. */
export function MonitorTypePicker({
  value,
  onChange,
  disabled,
}: {
  value: 'usage' | ProbeType
  onChange: (type: 'usage' | ProbeType) => void
  disabled?: boolean
}) {
  const t = useT()
  return (
    <RadioGroupPrimitive.Root
      aria-label={t('Monitor type')}
      value={value}
      onValueChange={(next) => onChange(next as 'usage' | ProbeType)}
      disabled={disabled}
      orientation="horizontal"
      className="flex flex-wrap gap-2"
    >
      {MONITOR_TYPES.map(({ value: type, label, icon: Icon }) => (
        <RadioGroupPrimitive.Item
          key={type}
          value={type}
          className={cn(
            'inline-flex h-8 items-center gap-1.5 rounded-md border px-3 text-[13px] transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-60',
            value === type
              ? 'border-foreground font-medium text-foreground ring-1 ring-inset ring-foreground'
              : 'border-border text-muted-foreground hover:bg-accent/40',
          )}
        >
          <Icon aria-hidden className="h-3.5 w-3.5" />
          {t(label)}
        </RadioGroupPrimitive.Item>
      ))}
    </RadioGroupPrimitive.Root>
  )
}

function Field({
  id,
  label,
  hint,
  className,
  children,
}: {
  id: string
  label: string
  hint?: string
  className?: string
  children: ReactNode
}) {
  return (
    <div className={cn('min-w-0', className)}>
      <Label htmlFor={id} className="text-[13px] font-semibold">
        {label}
      </Label>
      <div className="mt-2">{children}</div>
      {hint && (
        <p className="mt-1.5 text-[12px] text-muted-foreground">{hint}</p>
      )}
    </div>
  )
}

interface ProbeFieldsProps {
  form: ProbeForm
  onChange: (changes: Partial<ProbeForm>) => void
  disabled?: boolean
}

function HostFields({ form, onChange, disabled }: ProbeFieldsProps) {
  const t = useT()
  return (
    <div className="grid gap-4 sm:grid-cols-[1fr_120px]">
      <Field id="probe-host" label={t('Host')}>
        <Input
          id="probe-host"
          dir="ltr"
          placeholder={
            form.type === 'smtp' ? 'mail.example.com' : 'example.com'
          }
          value={form.host}
          onChange={(event) => onChange({ host: event.target.value })}
          disabled={disabled}
        />
      </Field>
      <Field id="probe-port" label={t('Port')}>
        <NumberInput
          id="probe-port"
          min={1}
          max={65535}
          value={form.port}
          onValueChange={(port) => onChange({ port })}
          disabled={disabled}
        />
      </Field>
    </div>
  )
}

export function ProbeFields({
  projectId,
  form,
  onChange,
  disabled,
}: ProbeFieldsProps & { projectId: string }) {
  const t = useT()
  const { project } = useProject(projectId)
  const { plan } = useOrganizationPlan(project?.teamId)
  const minimum = alertingLimits(plan).probeInterval
  const definition = PROBE_TYPES.find((entry) => entry.value === form.type)

  // The API refuses an interval shorter than the plan's, so start new checks on one it accepts.
  useEffect(() => {
    if (!disabled && form.interval < minimum) {
      onChange({ interval: defaultProbeInterval(minimum) })
    }
  }, [disabled, form.interval, minimum, onChange])

  return (
    <div className="space-y-7">
      <section>
        <SectionHeading
          title={t('Target')}
          description={definition ? t(definition.description) : undefined}
        />
        <div className="space-y-4 rounded-xl border border-border bg-muted/20 p-4">
          {form.type === 'http' && (
            <>
              <div className="grid gap-4 sm:grid-cols-[120px_1fr]">
                <Field id="probe-method" label={t('Method')}>
                  <Select
                    value={form.method}
                    onValueChange={(next) =>
                      onChange({ method: next as HttpMethod })
                    }
                    disabled={disabled}
                  >
                    <SelectTrigger id="probe-method" className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {HTTP_METHODS.map((method) => (
                        <SelectItem key={method} value={method}>
                          {method}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </Field>
                <Field id="probe-url" label={t('URL')}>
                  <Input
                    id="probe-url"
                    type="url"
                    dir="ltr"
                    placeholder="https://example.com/health"
                    value={form.url}
                    onChange={(event) => onChange({ url: event.target.value })}
                    disabled={disabled}
                  />
                </Field>
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <Field
                  id="probe-status-codes"
                  label={t('Expected status codes')}
                  hint={t('Separate codes with commas.')}
                >
                  <Input
                    id="probe-status-codes"
                    dir="ltr"
                    placeholder={t('Any 2xx status')}
                    value={form.statusCodes}
                    onChange={(event) =>
                      onChange({ statusCodes: event.target.value })
                    }
                    disabled={disabled}
                  />
                </Field>
                <Field
                  id="probe-certificate-days"
                  label={t('Certificate expiry warning')}
                  hint={t(
                    'Days before an HTTPS certificate expires to fire. 0 never warns early.',
                  )}
                >
                  <NumberInput
                    id="probe-certificate-days"
                    min={0}
                    max={398}
                    value={form.certificateDays}
                    onValueChange={(certificateDays) =>
                      onChange({ certificateDays })
                    }
                    disabled={disabled}
                  />
                </Field>
              </div>
              <Field
                id="probe-headers"
                label={t('Headers')}
                hint={t('One per line, as Name: value.')}
              >
                <Textarea
                  id="probe-headers"
                  dir="ltr"
                  className="font-mono text-[12px]"
                  placeholder="Accept: application/json"
                  value={form.headers}
                  onChange={(event) =>
                    onChange({ headers: event.target.value })
                  }
                  disabled={disabled}
                />
              </Field>
              {!BODILESS_METHODS.includes(form.method) && (
                <Field id="probe-body" label={t('Body')}>
                  <Textarea
                    id="probe-body"
                    dir="ltr"
                    className="font-mono text-[12px]"
                    value={form.body}
                    onChange={(event) => onChange({ body: event.target.value })}
                    disabled={disabled}
                    maxLength={16384}
                  />
                </Field>
              )}
            </>
          )}

          {form.type === 'dns' && (
            <>
              <div className="grid gap-4 sm:grid-cols-[1fr_120px]">
                <Field id="probe-domain" label={t('Domain')}>
                  <Input
                    id="probe-domain"
                    dir="ltr"
                    placeholder="example.com"
                    value={form.domain}
                    onChange={(event) =>
                      onChange({ domain: event.target.value })
                    }
                    disabled={disabled}
                  />
                </Field>
                <Field id="probe-record" label={t('Record type')}>
                  <Select
                    value={form.record}
                    onValueChange={(next) =>
                      onChange({ record: next as DnsRecord })
                    }
                    disabled={disabled}
                  >
                    <SelectTrigger id="probe-record" className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {DNS_RECORDS.map((record) => (
                        <SelectItem key={record} value={record}>
                          {record}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </Field>
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <Field
                  id="probe-answer"
                  label={t('Expected answer')}
                  hint={t('Leave empty to accept any answer.')}
                >
                  <Input
                    id="probe-answer"
                    dir="ltr"
                    placeholder="203.0.113.10"
                    value={form.answer}
                    onChange={(event) =>
                      onChange({ answer: event.target.value })
                    }
                    disabled={disabled}
                  />
                </Field>
                <Field
                  id="probe-resolver"
                  label={t('Resolver')}
                  hint={t("Leave empty to use the server's resolver.")}
                >
                  <Input
                    id="probe-resolver"
                    dir="ltr"
                    placeholder="1.1.1.1"
                    value={form.resolver}
                    onChange={(event) =>
                      onChange({ resolver: event.target.value })
                    }
                    disabled={disabled}
                  />
                </Field>
              </div>
            </>
          )}

          {form.type === 'tcp' && (
            <HostFields form={form} onChange={onChange} disabled={disabled} />
          )}

          {form.type === 'smtp' && (
            <>
              <HostFields form={form} onChange={onChange} disabled={disabled} />
              <Field id="probe-tls-mode" label={t('TLS')}>
                <Select
                  value={form.tlsMode || 'port'}
                  onValueChange={(next) =>
                    onChange({
                      tlsMode: (next === 'port' ? '' : next) as SmtpTlsMode,
                    })
                  }
                  disabled={disabled}
                >
                  <SelectTrigger id="probe-tls-mode" className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {/* Radix selects take no empty value, so the derived mode is `port`. */}
                    {TLS_MODES.map((mode) => (
                      <SelectItem
                        key={mode.value || 'port'}
                        value={mode.value || 'port'}
                      >
                        {t(mode.label)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
            </>
          )}
        </div>
      </section>

      <section>
        <SectionHeading
          title={t('Checks')}
          description={t(
            'The monitor fires when a check fails, and resolves when one passes again.',
          )}
        />
        <div className="flex flex-wrap items-center gap-2">
          <Word>{t('Check every')}</Word>
          <Select
            value={String(form.interval)}
            onValueChange={(next) => onChange({ interval: Number(next) })}
            disabled={disabled}
          >
            <SelectTrigger
              className="h-9 w-auto min-w-32"
              aria-label={t('Check interval')}
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {durationOptions(INTERVALS, form.interval, t).map((option) => (
                <SelectItem
                  key={option.value}
                  value={String(option.value)}
                  disabled={option.value < minimum}
                >
                  {option.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Word>{t('and fail after')}</Word>
          <Select
            value={String(form.timeout)}
            onValueChange={(next) => onChange({ timeout: Number(next) })}
            disabled={disabled}
          >
            <SelectTrigger
              className="h-9 w-auto min-w-32"
              aria-label={t('Check timeout')}
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {durationOptions(TIMEOUTS, form.timeout, t).map((option) => (
                <SelectItem
                  key={option.value}
                  value={String(option.value)}
                  disabled={option.value > form.interval}
                >
                  {option.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        {minimum > INTERVALS[0].value && (
          <p className="mt-2 text-[12px] text-muted-foreground">
            {t('Your plan checks at most every {interval}.').replace(
              '{interval}',
              durationOptions(INTERVALS, minimum, t).find(
                (option) => option.value === minimum,
              )?.label ?? `${minimum}s`,
            )}
          </p>
        )}
      </section>

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
        placeholder={suggestedProbeName(form) || t('Name')}
        onChange={onChange}
        disabled={disabled}
      />
    </div>
  )
}
