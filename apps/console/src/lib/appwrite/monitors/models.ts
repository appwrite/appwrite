/**
 * Monitor and alert models: the generated `Api.Monitor` and `Api.Alert`, with the
 * enums the console matches on read as string unions. The enums already carry the
 * edition-only metrics, resource types and `budget` monitors, so the unions are
 * exactly their values.
 */

import type * as Api from './api'
import type {
  AlertComparator as Comparator,
  AlertMetric as Metric,
  AlertStatus as Status,
  MonitorResourceType,
  MonitorResult as Outcome,
  MonitorSeverity as Level,
  MonitorStatus as State,
  MonitorType as Kind,
} from './api'

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
  Api.Monitor,
  'type' | 'resourceType' | 'status' | 'severity' | 'result'
> & {
  type: MonitorType
  resourceType: AlertResourceType
  status: MonitorStatus
  severity: MonitorSeverity
  result?: MonitorResult | null
}

export type MonitorList = Omit<Api.MonitorList, 'monitors'> & {
  monitors: Monitor[]
}

export type Alert = Omit<
  Api.Alert,
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

export type AlertList = Omit<Api.AlertList, 'alerts'> & {
  alerts: Alert[]
}

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
}

export type HttpMethod =
  | 'GET'
  | 'HEAD'
  | 'POST'
  | 'PUT'
  | 'PATCH'
  | 'DELETE'
  | 'OPTIONS'

export type DnsRecord = 'A' | 'AAAA' | 'CNAME' | 'MX' | 'TXT' | 'NS'

/** Empty derives the mode from the port: 465 implicit, 587 STARTTLS, otherwise none. */
export type SmtpTlsMode = 'none' | 'starttls' | 'implicit' | ''

/** Fields every probe monitor takes besides its target. */
export type ProbeSettings = {
  name: string
  /** Seconds between two checks. */
  interval?: number
  /** Seconds a check may take; no longer than `interval`. */
  timeout?: number
  cooldown?: number
  notify?: string[]
  severity?: MonitorSeverity
  enabled?: boolean
}

export type HttpTarget = {
  url: string
  method?: HttpMethod
  headers?: Record<string, string>
  body?: string
  /** Empty accepts any 2xx status. */
  statusCodes?: number[]
  /** 0 checks the certificate without warning before it expires. */
  certificateDays?: number
}

export type DnsTarget = {
  domain: string
  record?: DnsRecord
  /** An IP address with an optional port; empty for the server's resolver. */
  resolver?: string
  /** Empty accepts any answer. */
  answer?: string
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
