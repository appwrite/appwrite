/**
 * Probe monitors check a target on their own interval: an HTTP URL, a DNS
 * name, a TCP port or a mail server. What the console offers for each, and
 * how it reads their targets and results back.
 */

import type {
  DnsRecord,
  HttpMethod,
  Monitor,
  MonitorResult,
  ProbeType,
  SmtpTlsMode,
} from '@/lib/appwrite/monitors'

export const PROBE_TYPES: {
  value: ProbeType
  label: string
  description: string
}[] = [
  {
    value: 'http',
    label: 'HTTP',
    description:
      'Get notified when a URL stops answering with the status you expect, or its certificate is about to expire.',
  },
  {
    value: 'dns',
    label: 'DNS',
    description:
      'Get notified when a domain stops resolving, or stops answering with the value you expect.',
  },
  {
    value: 'tcp',
    label: 'TCP',
    description:
      'Get notified when a host stops accepting connections on a port.',
  },
  {
    value: 'smtp',
    label: 'SMTP',
    description:
      'Get notified when a mail server stops accepting sessions or its TLS handshake fails.',
  },
]

export function isProbeType(type: string): type is ProbeType {
  return PROBE_TYPES.some((entry) => entry.value === type)
}

export const HTTP_METHODS: HttpMethod[] = [
  'GET',
  'HEAD',
  'POST',
  'PUT',
  'PATCH',
  'DELETE',
  'OPTIONS',
]

export const DNS_RECORDS: DnsRecord[] = [
  'A',
  'AAAA',
  'CNAME',
  'MX',
  'TXT',
  'NS',
]

export const TLS_MODES: { value: SmtpTlsMode; label: string }[] = [
  { value: 'auto', label: 'From the port' },
  { value: 'none', label: 'None' },
  { value: 'starttls', label: 'Upgrade with STARTTLS' },
  { value: 'implicit', label: 'Implicit TLS' },
]

/** The mode the server uses for an SMTP monitor set to `auto`. */
export function smtpTlsMode(port: number, mode: SmtpTlsMode): SmtpTlsMode {
  if (mode !== 'auto') return mode
  return port === 465 ? 'implicit' : port === 587 ? 'starttls' : 'none'
}

export const INTERVALS: { value: number; label: string }[] = [
  { value: 30, label: '30 seconds' },
  { value: 60, label: '1 minute' },
  { value: 300, label: '5 minutes' },
  { value: 900, label: '15 minutes' },
  { value: 3600, label: '1 hour' },
]

export const TIMEOUTS: { value: number; label: string }[] = [
  { value: 5, label: '5 seconds' },
  { value: 10, label: '10 seconds' },
  { value: 30, label: '30 seconds' },
  { value: 60, label: '1 minute' },
]

/** How each result reads where the list and the drawers show it. */
export const RESULT_LABELS: Record<MonitorResult, string> = {
  ok: 'Responding',
  dns_failed: 'Name did not resolve',
  connect_refused: 'Connection refused',
  connect_timeout: 'Connection timed out',
  connect_failed: 'Connection failed',
  tls_failed: 'TLS handshake failed',
  tls_expiring: 'TLS certificate expiring',
  status_mismatch: 'Unexpected status code',
  answer_mismatch: 'Unexpected answer',
  smtp_rejected: 'Server refused the session',
  target_denied: 'Target is in a denied network',
  timeout: 'Timed out',
  rule_matched: 'A rule matched',
}

export function resultLabel(result: MonitorResult): string {
  return RESULT_LABELS[result] ?? result
}

/** `GET https://example.com/health`, `A example.com`, `mail.example.com:587` */
export function probeTarget(
  monitor: Pick<
    Monitor,
    'type' | 'url' | 'method' | 'domain' | 'record' | 'host' | 'port'
  >,
): string {
  switch (monitor.type) {
    case 'http':
      return `${monitor.method || 'GET'} ${monitor.url}`
    case 'dns':
      return `${monitor.record || 'A'} ${monitor.domain}`
    case 'tcp':
    case 'smtp':
      return `${monitor.host}:${monitor.port}`
    default:
      return ''
  }
}

export function formatLatency(milliseconds: number): string {
  return milliseconds < 1000
    ? `${Math.round(milliseconds)} ms`
    : `${parseFloat((milliseconds / 1000).toFixed(2))} s`
}

/** Header lines as `Name: value`, one per line, the way they are typed into the form. */
export function formatHeaders(headers: object | null | undefined): string {
  return Object.entries(headers ?? {})
    .map(([name, value]) => `${name}: ${String(value)}`)
    .join('\n')
}

/** Reads `Name: value` lines back into headers; null when a line has no name. */
export function parseHeaders(text: string): Record<string, string> | null {
  const headers: Record<string, string> = {}
  for (const line of text.split('\n')) {
    if (!line.trim()) continue
    const separator = line.indexOf(':')
    const name = separator === -1 ? '' : line.slice(0, separator).trim()
    if (!name) return null
    headers[name] = line.slice(separator + 1).trim()
  }
  return headers
}

/** Reads `200, 301` into status codes; null when one is not a status code. */
export function parseStatusCodes(text: string): number[] | null {
  const codes: number[] = []
  for (const part of text.split(/[\s,]+/)) {
    if (!part) continue
    const code = Number(part)
    if (!Number.isInteger(code) || code < 100 || code > 599) return null
    if (!codes.includes(code)) codes.push(code)
  }
  return codes
}
