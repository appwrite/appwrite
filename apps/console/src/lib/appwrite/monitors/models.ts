/**
 * Monitor, alert and escalation models: the Console SDK's, with the enums the
 * console matches on read as string unions, so a literal like `'http'` compares
 * and assigns without importing the enum.
 */

import type {
  AlertComparator as Comparator,
  AlertMetric as Metric,
  AlertStatus as Status,
  Models,
  MonitorMethod,
  MonitorRecord,
  MonitorResourceType,
  MonitorResult as Outcome,
  MonitorSeverity as Level,
  MonitorStatus as State,
  MonitorTlsMode,
  MonitorType as Kind,
} from '@appwrite.io/console'

export type AlertMetric = `${Metric}`

export type AlertResourceType = `${MonitorResourceType}`

export type AlertComparator = `${Comparator}`

/** `budget` monitors live on organization routes, where an edition has them. */
export type MonitorType = `${Kind}`

/** How urgent a monitor's alerts are; only `critical` changes how a push is delivered. */
export type MonitorSeverity = `${Level}`

export type MonitorStatus = `${State}`

/** Monitors that check a target on their own interval instead of reading usage. */
export type ProbeType = Exclude<MonitorType, 'usage' | 'budget'>

/** What a probe monitor's last check found; only `ok` passes. */
export type MonitorResult = `${Outcome}`

export type AlertStatus = `${Status}`

export type Monitor = Omit<
  Models.Monitor,
  | 'type'
  | 'resourceType'
  | 'status'
  | 'severity'
  | 'result'
  | 'method'
  | 'record'
  | 'tlsMode'
> & {
  type: MonitorType
  resourceType: AlertResourceType
  status: MonitorStatus
  severity: MonitorSeverity
  result?: MonitorResult | null
  method?: HttpMethod | null
  record?: DnsRecord | null
  tlsMode?: SmtpTlsMode | null
}

export type MonitorList = { total: number; monitors: Monitor[] }

export type Alert = Omit<
  Models.Alert,
  | 'type'
  | 'resourceType'
  | 'status'
  | 'metric'
  | 'comparator'
  | 'severity'
  | 'result'
> & {
  type: MonitorType
  resourceType: AlertResourceType
  status: AlertStatus
  severity: MonitorSeverity
  /** Null on a probe monitor's alert, which carries `result` instead. */
  metric?: AlertMetric | null
  comparator?: AlertComparator | null
  result?: MonitorResult | null
}

export type AlertList = { total: number; alerts: Alert[] }

/** A usage monitor's writable fields, as `createUsageMonitor` takes them. */
export type MonitorInput = {
  name: string
  /** Metric comparisons; build them with `buildRules`. */
  rules: string[]
  /** Ignored for gauge metrics. */
  window?: number
  cooldown?: number
  /** Organization roles emailed and notified in the console; none relies on the alert events alone. */
  notify?: string[]
  /** Critical pushes ring through silent and Do Not Disturb on devices that allow critical alerts. */
  severity?: MonitorSeverity
  enabled?: boolean
  /** An organization escalation that replaces `notify`; empty notifies the roles. */
  escalationId?: string
}

/** Who one escalation step notifies, after waiting `delay` seconds. */
export type EscalationStep = Models.EscalationStep

/** An organization's escalation: steps notified in turn until someone acknowledges the alert. */
export type Escalation = Models.Escalation

/** An escalation's writable fields. */
export type EscalationInput = {
  name: string
  steps: EscalationStep[]
  repeat: number
}

export type HttpMethod = `${MonitorMethod}`

export type DnsRecord = `${MonitorRecord}`

/** `auto` derives the mode from the port: 465 implicit, 587 STARTTLS, otherwise none. */
export type SmtpTlsMode = `${MonitorTlsMode}`

/** Fields every probe monitor takes besides its target. */
export type ProbeSettings = {
  name: string
  /** Seconds between two checks. */
  interval?: number
  /** Seconds a check may take; no longer than `interval`. */
  timeout?: number
  cooldown?: number
  notify?: string[]
  /** An organization escalation that replaces `notify`; empty notifies the roles. */
  escalationId?: string
  severity?: MonitorSeverity
  enabled?: boolean
  /** Queries over what a check saw that describe the failing state; build them with `buildProbeRules`. */
  rules?: string[]
}

export type HttpTarget = {
  url: string
  method?: HttpMethod
  headers?: Record<string, string>
  body?: string
}

export type DnsTarget = {
  domain: string
  record?: DnsRecord
  /** An IP address with an optional port; empty for the server's resolver. */
  resolver?: string
}

export type TcpTarget = { host: string; port: number }

export type SmtpTarget = { host: string; port: number; tlsMode?: SmtpTlsMode }

/** A probe monitor's type, with the writable fields its create method takes. */
export type ProbeMonitorInput =
  | { type: 'http'; params: ProbeSettings & HttpTarget }
  | { type: 'dns'; params: ProbeSettings & DnsTarget }
  | { type: 'tcp'; params: ProbeSettings & TcpTarget }
  | { type: 'smtp'; params: ProbeSettings & SmtpTarget }

/** Fields an update of any probe monitor can change; only the ones passed are sent. */
export type ProbeMonitorChanges = Partial<
  ProbeSettings & HttpTarget & DnsTarget & SmtpTarget
>
