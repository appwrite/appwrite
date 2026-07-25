/**
 * Fetch Cloudflare zone traffic stats with optional attribute filters.
 *
 * Datasets:
 *   - No filters: httpRequests1dGroups / httpRequests1hGroups (longer retention)
 *   - With --filter / --group-by / friendly filter flags: httpRequestsAdaptiveGroups
 *     (~32d retention on many plans; check --settings)
 *
 * Filters:
 *   Any AdaptiveGroups filter field works via:
 *     --filter=<field>=<value>
 *   Operators are part of the field name:
 *     --filter=clientCountryName=US
 *     --filter=clientCountryName_in=US,DE,FR
 *     --filter=clientRequestPath_like=/api/%
 *     --filter=edgeResponseStatus_geq=400
 *     --filter=botScore_lt=30
 *     --filter=cacheStatus_neq=hit
 *
 * Run: bun run cloudflare-stats --help
 *      bun run cloudflare-stats --list-filters
 */

import 'dotenv/config'

import { writeFile } from 'node:fs/promises'

const GRAPHQL_URL = 'https://api.cloudflare.com/client/v4/graphql'
const REST_URL = 'https://api.cloudflare.com/client/v4'

const CHUNK_DAYS = {
  hour: 3,
  day: 90,
  week: 90,
  month: 90,
  adaptive: 14,
} as const

type Format = 'table' | 'csv' | 'json'
type Interval = 'hour' | 'day' | 'week' | 'month'
type Dataset = 'rollup' | 'adaptive'

type CliOptions = {
  help: boolean
  settings: boolean
  listFilters: boolean
  listDimensions: boolean
  zone?: string
  start?: string
  end?: string
  days?: number
  interval: Interval
  format: Format
  out?: string
  limit: number
  groupBy: string[]
  /** Raw AdaptiveGroups filter object fields */
  filters: Record<string, unknown>
  eyeball: boolean
  forceAdaptive: boolean
}

type MetricRow = {
  period: string
  group?: Record<string, string | number | boolean | null>
  requests: number
  pageViews: number
  bytes: number
  cachedBytes: number
  threats: number
  uniques: number
}

type GraphQLError = {
  message: string
}

/** Friendly CLI flag → AdaptiveGroups filter field (equality unless noted). */
const FILTER_ALIASES: Record<string, string> = {
  country: 'clientCountryName',
  host: 'clientRequestHTTPHost',
  path: 'clientRequestPath',
  method: 'clientRequestHTTPMethodName',
  status: 'edgeResponseStatus',
  'origin-status': 'originResponseStatus',
  cache: 'cacheStatus',
  colo: 'coloCode',
  asn: 'clientAsn',
  'asn-desc': 'clientASNDescription',
  ip: 'clientIP',
  source: 'requestSource',
  scheme: 'clientRequestScheme',
  protocol: 'clientRequestHTTPProtocol',
  ssl: 'clientSSLProtocol',
  referer: 'clientRequestReferer',
  'referer-host': 'clientRefererHost',
  query: 'clientRequestQuery',
  ua: 'userAgent',
  browser: 'userAgentBrowser',
  os: 'userAgentOS',
  device: 'clientDeviceType',
  'content-type': 'edgeResponseContentTypeName',
  'bot-score': 'botScore',
  'bot-decision': 'botManagementDecision',
  'security-action': 'securityAction',
  'security-source': 'securitySource',
  ja3: 'ja3Hash',
  ja4: 'ja4',
  'verified-bot': 'verifiedBotCategory',
  'waf-score': 'wafAttackScore',
  'waf-class': 'wafAttackScoreClass',
}

/** Friendly group-by name → dimension field. */
const GROUP_ALIASES: Record<string, string> = {
  ...FILTER_ALIASES,
  time: 'datetimeHour',
  hour: 'datetimeHour',
  day: 'date',
  date: 'date',
}

const INTERVAL_ALIASES: Record<string, Interval> = {
  hour: 'hour',
  hours: 'hour',
  hourly: 'hour',
  '1h': 'hour',
  h: 'hour',
  day: 'day',
  days: 'day',
  daily: 'day',
  '1d': 'day',
  d: 'day',
  week: 'week',
  weeks: 'week',
  weekly: 'week',
  '1w': 'week',
  w: 'week',
  month: 'month',
  months: 'month',
  monthly: 'month',
  '1m': 'month',
  mo: 'month',
}

/** Numeric Adaptive filter base fields (values coerced to number). */
const NUMERIC_FILTER_BASES = new Set([
  'botScore',
  'botScoreBucketBy10',
  'cacheReserveUsed',
  'clientAsn',
  'contentScanHasFailed',
  'contentScanNumMaliciousObj',
  'contentScanNumObj',
  'edgeDnsResponseTimeMs',
  'edgeResponseStatus',
  'edgeTimeToFirstByteMs',
  'originASN',
  'originResponseDurationMs',
  'originResponseHeaderReceiveDurationMs',
  'originResponseStatus',
  'originTcpHandshakeDurationMs',
  'originTlsHandshakeDurationMs',
  'sampleInterval',
  'wafAttackScore',
  'wafPathTraversalAttackScore',
  'wafRceAttackScore',
  'wafSqliAttackScore',
  'wafXssAttackScore',
  'zoneVersion',
  'httpApplicationVersion',
])

const LIST_OPS = new Set(['_in', '_notin', '_hasany', '_hasall', '_has', '_nothas'])

const TIME_DIMENSION: Record<Interval, string> = {
  hour: 'datetimeHour',
  day: 'date',
  week: 'date',
  month: 'date',
}

function printHelp(): void {
  console.log(`Cloudflare zone traffic stats

Usage:
  bun run cloudflare-stats [options]

Time:
  --zone=<id-or-name>     Zone ID or domain (default: CLOUDFLARE_ZONE_ID)
  --interval=hour|day|week|month   Bucket size (default: day)
  --days=<n>              Last N days (default: 90, or 7 for hour)
  --start=YYYY-MM-DD      Range start
  --end=YYYY-MM-DD        Range end (default: today)

Output:
  --format=table|csv|json
  --out=<path>
  --limit=<n>             Max rows for adaptive/group-by queries (default: 1000)
  --settings              Dataset retention limits
  --list-filters          Show filter aliases + how to pass any field
  --list-dimensions       Show group-by aliases
  --adaptive              Force AdaptiveGroups dataset (even without filters)
  --help

Filters (switch to AdaptiveGroups, ~32d retention typically):
  --filter=<field>=<value>   Any AdaptiveGroups filter field (see --list-filters)
  --filter=<field>_in=a,b    List operators: _in _notin _has _hasany _hasall
  --filter=<field>_like=x%   String ops: _like _notlike _neq
  --filter=<field>_geq=n     Numeric ops: _gt _geq _lt _leq _neq
  --eyeball                  Shortcut for requestSource=eyeball (end-user traffic)

Friendly filter aliases (same as --filter=<mapped>=...):
${Object.entries(FILTER_ALIASES)
  .map(([k, v]) => `  --${k}=<value>`.padEnd(28) + `→ ${v}`)
  .join('\n')}

  Like / in / neq variants for aliases:
    --path-like=/api/%
    --country-in=US,DE
    --status-geq=400
    --cache-neq=hit

Group by:
  --group-by=<dim,dim,...>   e.g. --group-by=country,status
                             Use --list-dimensions for aliases / raw field names

Examples:
  bun run cloudflare-stats --days=90
  bun run cloudflare-stats --interval=hour --days=2
  bun run cloudflare-stats --country=US --days=14
  bun run cloudflare-stats --host=api.example.com --path-like=/v1/% --days=7
  bun run cloudflare-stats --status-geq=400 --group-by=status,path --days=3
  bun run cloudflare-stats --filter=clientAsn=13335 --filter=cacheStatus=hit
  bun run cloudflare-stats --eyeball --group-by=country --days=7 --format=csv
`)
}

function printFilterHelp(): void {
  console.log(`Filter usage

Pass any httpRequestsAdaptiveGroups filter field with:
  --filter=<field>=<value>

Operators are suffixes on the field name (Cloudflare GraphQL style):
  equals:     --filter=clientCountryName=US
  in/notin:   --filter=clientCountryName_in=US,DE,FR
  like:       --filter=clientRequestPath_like=/api/%
  compare:    --filter=edgeResponseStatus_geq=400
  array has:  --filter=botDetectionTags_hasany=tag1,tag2

Friendly aliases (shorter flags):
`)
  console.table(
    Object.entries(FILTER_ALIASES).map(([alias, field]) => ({
      flag: `--${alias}`,
      field,
      example: `--${alias}=value`,
    })),
  )
  console.log(`
Operator variants for aliases:
  --country-in=US,DE
  --path-like=/api/%
  --status-geq=400
  --status-lt=500
  --cache-neq=hit
  --host-notin=cdn.example.com

Also: --eyeball  →  requestSource=eyeball

Note: attribute filters use AdaptiveGroups (shorter history than plain daily totals).
Run --settings to see notOlderThanDays for httpRequestsAdaptiveGroups.
`)
}

function printDimensionHelp(): void {
  console.log(`Group-by usage

  --group-by=<dim,dim,...>

Aliases:`)
  console.table(
    Object.entries(GROUP_ALIASES).map(([alias, field]) => ({
      alias,
      dimension: field,
    })),
  )
  console.log(`
You can also pass raw AdaptiveGroups dimension names, e.g.:
  --group-by=clientRequestHTTPHost,edgeResponseStatus,coloCode

Examples:
  bun run cloudflare-stats --group-by=country --days=7
  bun run cloudflare-stats --group-by=host,path --status-geq=400 --days=3
`)
}

function splitCsv(value: string): string[] {
  return value
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean)
}

function baseFieldName(field: string): string {
  return field
    .replace(/_(geq|gt|leq|lt|neq|in|notin|like|notlike|has|hasany|hasall|nothas|isempty)$/, '')
}

function operatorSuffix(field: string): string | null {
  const m = field.match(/_(geq|gt|leq|lt|neq|in|notin|like|notlike|has|hasany|hasall|nothas|isempty)$/)
  return m ? `_${m[1]}` : null
}

function coerceFilterValue(field: string, raw: string): unknown {
  const op = operatorSuffix(field)
  const base = baseFieldName(field)

  if (op === '_isempty') {
    return raw === '' || raw === 'true' || raw === '1'
  }

  if (op && LIST_OPS.has(op)) {
    const parts = splitCsv(raw)
    if (NUMERIC_FILTER_BASES.has(base)) {
      return parts.map((p) => {
        const n = Number(p)
        if (!Number.isFinite(n)) throw new Error(`Expected number in list for ${field}: ${p}`)
        return n
      })
    }
    return parts
  }

  if (raw === 'true') return true
  if (raw === 'false') return false

  if (NUMERIC_FILTER_BASES.has(base) || /Status$|Score$|Asn$|ASN$|Ms$|Version$|Interval$|BucketBy10$/.test(base)) {
    const n = Number(raw)
    if (!Number.isFinite(n)) throw new Error(`Expected number for ${field}, got: ${raw}`)
    return n
  }

  return raw
}

function setFilter(filters: Record<string, unknown>, field: string, raw: string): void {
  if (!field || field.includes(' ')) {
    throw new Error(`Invalid filter field: ${field}`)
  }
  filters[field] = coerceFilterValue(field, raw)
}

function parseAliasFilterArg(arg: string): { field: string; value: string } | null {
  // --country=US  or --path-like=/api/%  or --status-geq=400
  const m = arg.match(
    /^--([a-z0-9-]+?)(?:-(in|notin|like|notlike|neq|geq|gt|leq|lt|has|hasany|hasall))?=([\s\S]*)$/i,
  )
  if (!m) return null
  const alias = m[1].toLowerCase()
  const mapped = FILTER_ALIASES[alias]
  if (!mapped) return null
  const op = m[2] ? `_${m[2].toLowerCase()}` : ''
  return { field: `${mapped}${op}`, value: m[3] }
}

function parseArgs(argv: string[]): CliOptions {
  const opts: CliOptions = {
    help: false,
    settings: false,
    listFilters: false,
    listDimensions: false,
    interval: 'day',
    format: 'table',
    limit: 1000,
    groupBy: [],
    filters: {},
    eyeball: false,
    forceAdaptive: false,
  }

  for (const arg of argv) {
    if (arg === '--help' || arg === '-h') {
      opts.help = true
      continue
    }
    if (arg === '--settings') {
      opts.settings = true
      continue
    }
    if (arg === '--list-filters') {
      opts.listFilters = true
      continue
    }
    if (arg === '--list-dimensions') {
      opts.listDimensions = true
      continue
    }
    if (arg === '--eyeball') {
      opts.eyeball = true
      continue
    }
    if (arg === '--adaptive') {
      opts.forceAdaptive = true
      continue
    }
    if (arg.startsWith('--zone=')) {
      opts.zone = arg.slice('--zone='.length).trim()
      continue
    }
    if (arg.startsWith('--start=')) {
      opts.start = arg.slice('--start='.length).trim()
      continue
    }
    if (arg.startsWith('--end=')) {
      opts.end = arg.slice('--end='.length).trim()
      continue
    }
    if (arg.startsWith('--days=')) {
      const n = Number(arg.slice('--days='.length))
      if (!Number.isFinite(n) || n < 1) throw new Error(`Invalid --days: ${arg}`)
      opts.days = Math.floor(n)
      continue
    }
    if (arg.startsWith('--limit=')) {
      const n = Number(arg.slice('--limit='.length))
      if (!Number.isFinite(n) || n < 1) throw new Error(`Invalid --limit: ${arg}`)
      opts.limit = Math.floor(n)
      continue
    }
    if (arg.startsWith('--interval=')) {
      const raw = arg.slice('--interval='.length).trim().toLowerCase()
      const interval = INTERVAL_ALIASES[raw]
      if (!interval) throw new Error(`Invalid --interval: ${raw}`)
      opts.interval = interval
      continue
    }
    if (arg.startsWith('--format=')) {
      const format = arg.slice('--format='.length).trim() as Format
      if (!['table', 'csv', 'json'].includes(format)) {
        throw new Error(`Invalid --format: ${format}`)
      }
      opts.format = format
      continue
    }
    if (arg.startsWith('--out=')) {
      opts.out = arg.slice('--out='.length).trim()
      continue
    }
    if (arg.startsWith('--group-by=')) {
      opts.groupBy = splitCsv(arg.slice('--group-by='.length)).map((d) => {
        const key = d.toLowerCase()
        return GROUP_ALIASES[key] ?? d
      })
      continue
    }
    if (arg.startsWith('--filter=')) {
      const body = arg.slice('--filter='.length)
      const eq = body.indexOf('=')
      if (eq <= 0) throw new Error(`Invalid --filter (use field=value): ${arg}`)
      setFilter(opts.filters, body.slice(0, eq), body.slice(eq + 1))
      continue
    }

    const aliasFilter = parseAliasFilterArg(arg)
    if (aliasFilter) {
      setFilter(opts.filters, aliasFilter.field, aliasFilter.value)
      continue
    }

    throw new Error(`Unknown argument: ${arg}\nRun with --help for usage.`)
  }

  if (opts.eyeball) {
    opts.filters.requestSource = 'eyeball'
  }

  return opts
}

function usesAdaptive(opts: CliOptions): boolean {
  return opts.forceAdaptive || Object.keys(opts.filters).length > 0 || opts.groupBy.length > 0
}

function requireToken(): string {
  const token = process.env.CLOUDFLARE_API_TOKEN?.trim()
  if (!token) {
    throw new Error(
      'Missing CLOUDFLARE_API_TOKEN. Create a token at https://dash.cloudflare.com/profile/api-tokens with Zone Analytics Read, then add it to .env',
    )
  }
  return token
}

function formatDateUTC(d: Date): string {
  return d.toISOString().slice(0, 10)
}

function formatDateTimeUTC(d: Date): string {
  return d.toISOString().replace(/\.\d{3}Z$/, 'Z')
}

function parseDateUTC(value: string): Date {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    throw new Error(`Invalid date "${value}". Use YYYY-MM-DD.`)
  }
  const d = new Date(`${value}T00:00:00.000Z`)
  if (Number.isNaN(d.getTime()) || formatDateUTC(d) !== value) {
    throw new Error(`Invalid date "${value}".`)
  }
  return d
}

function addDaysUTC(d: Date, days: number): Date {
  const next = new Date(d)
  next.setUTCDate(next.getUTCDate() + days)
  return next
}

function defaultDaysForInterval(interval: Interval, adaptive: boolean): number {
  if (adaptive) return 7
  return interval === 'hour' ? 7 : 90
}

function resolveRange(opts: CliOptions, adaptive: boolean): { start: string; end: string } {
  const end = opts.end ? parseDateUTC(opts.end) : parseDateUTC(formatDateUTC(new Date()))
  let start: Date
  if (opts.start) {
    start = parseDateUTC(opts.start)
  } else {
    const days = opts.days ?? defaultDaysForInterval(opts.interval, adaptive)
    start = addDaysUTC(end, -(days - 1))
  }
  if (start.getTime() > end.getTime()) {
    throw new Error(`Start date ${formatDateUTC(start)} is after end date ${formatDateUTC(end)}.`)
  }
  return { start: formatDateUTC(start), end: formatDateUTC(end) }
}

function* dateChunks(
  start: string,
  end: string,
  chunkDays: number,
): Generator<{ start: string; end: string }> {
  let cursor = parseDateUTC(start)
  const endDate = parseDateUTC(end)
  while (cursor.getTime() <= endDate.getTime()) {
    const chunkEnd = addDaysUTC(cursor, chunkDays - 1)
    const cappedEnd = chunkEnd.getTime() > endDate.getTime() ? endDate : chunkEnd
    yield { start: formatDateUTC(cursor), end: formatDateUTC(cappedEnd) }
    cursor = addDaysUTC(cappedEnd, 1)
  }
}

function* datetimeChunks(
  startDate: string,
  endDateInclusive: string,
  chunkDays: number,
): Generator<{ start: string; end: string }> {
  let cursor = parseDateUTC(startDate)
  const endExclusive = addDaysUTC(parseDateUTC(endDateInclusive), 1)
  while (cursor.getTime() < endExclusive.getTime()) {
    const chunkEnd = addDaysUTC(cursor, chunkDays)
    const cappedEnd = chunkEnd.getTime() > endExclusive.getTime() ? endExclusive : chunkEnd
    yield { start: formatDateTimeUTC(cursor), end: formatDateTimeUTC(cappedEnd) }
    cursor = cappedEnd
  }
}

type MetricSums = {
  requests: number
  pageViews: number
  bytes: number
  cachedBytes: number
  threats: number
  uniques: number
}

function emptySums(): MetricSums {
  return { requests: 0, pageViews: 0, bytes: 0, cachedBytes: 0, threats: 0, uniques: 0 }
}

function addSums(target: MetricSums, source: MetricSums): void {
  target.requests += source.requests
  target.pageViews += source.pageViews
  target.bytes += source.bytes
  target.cachedBytes += source.cachedBytes
  target.threats += source.threats
  target.uniques += source.uniques
}

function isoWeekPeriod(dateStr: string): string {
  const d = parseDateUTC(dateStr)
  const day = d.getUTCDay() || 7
  d.setUTCDate(d.getUTCDate() + 4 - day)
  const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1))
  const week = Math.ceil(((d.getTime() - yearStart.getTime()) / 86400000 + 1) / 7)
  return `${d.getUTCFullYear()}-W${String(week).padStart(2, '0')}`
}

function monthPeriod(dateStr: string): string {
  return dateStr.slice(0, 7)
}

function rollupRows(rows: MetricRow[], interval: 'week' | 'month'): MetricRow[] {
  const byKey = new Map<string, MetricRow>()
  for (const row of rows) {
    const period =
      interval === 'week' ? isoWeekPeriod(row.period.slice(0, 10)) : monthPeriod(row.period.slice(0, 10))
    const groupKey = row.group ? JSON.stringify(row.group) : ''
    const key = `${period}|${groupKey}`
    const existing = byKey.get(key)
    if (!existing) {
      byKey.set(key, { ...row, period, ...emptySums(), ...pickSums(row) })
    } else {
      addSums(existing, pickSums(row))
    }
  }
  return [...byKey.values()].sort((a, b) => {
    const p = a.period.localeCompare(b.period)
    if (p !== 0) return p
    return JSON.stringify(a.group ?? {}).localeCompare(JSON.stringify(b.group ?? {}))
  })
}

function pickSums(row: MetricRow): MetricSums {
  return {
    requests: row.requests,
    pageViews: row.pageViews,
    bytes: row.bytes,
    cachedBytes: row.cachedBytes,
    threats: row.threats,
    uniques: row.uniques,
  }
}

async function cfFetchJson<T>(token: string, url: string, init?: RequestInit): Promise<T> {
  const res = await fetch(url, {
    ...init,
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json',
      ...(init?.headers ?? {}),
    },
  })
  const json = (await res.json()) as T & {
    success?: boolean
    errors?: Array<{ message: string }>
  }
  if (!res.ok || json.success === false) {
    const detail =
      json.errors?.map((e) => e.message).join('; ') || `${res.status} ${res.statusText}`
    throw new Error(`Cloudflare API error: ${detail}`)
  }
  return json
}

async function graphql<T>(
  token: string,
  query: string,
  variables: Record<string, unknown>,
): Promise<T> {
  const json = await cfFetchJson<{
    data?: T
    errors?: GraphQLError[]
    success?: boolean
  }>(token, GRAPHQL_URL, {
    method: 'POST',
    body: JSON.stringify({ query, variables }),
  })
  if (json.errors?.length) {
    throw new Error(json.errors.map((e) => e.message).join('; '))
  }
  if (!json.data) throw new Error('Cloudflare GraphQL response missing data')
  return json.data
}

async function resolveZoneId(
  token: string,
  zone: string | undefined,
): Promise<{ zoneId: string; name?: string }> {
  const input = zone?.trim() || process.env.CLOUDFLARE_ZONE_ID?.trim()
  if (!input) {
    throw new Error(
      'Missing zone. Pass --zone=<id-or-domain> or set CLOUDFLARE_ZONE_ID in .env.',
    )
  }
  if (/^[a-f0-9]{32}$/i.test(input)) return { zoneId: input }

  const list = await cfFetchJson<{ result: Array<{ id: string; name: string }> }>(
    token,
    `${REST_URL}/zones?name=${encodeURIComponent(input)}&status=active`,
  )
  const match = list.result.find((z) => z.name.toLowerCase() === input.toLowerCase())
  if (!match) throw new Error(`No active Cloudflare zone found for "${input}".`)
  return { zoneId: match.id, name: match.name }
}

async function fetchSettings(token: string, zoneId: string): Promise<void> {
  const query = `
    query($zoneTag: String) {
      viewer {
        zones(filter: { zoneTag: $zoneTag }) {
          settings {
            httpRequests1dGroups { enabled notOlderThan maxDuration maxPageSize }
            httpRequests1hGroups { enabled notOlderThan maxDuration maxPageSize }
            httpRequestsAdaptiveGroups { enabled notOlderThan maxDuration maxPageSize }
          }
        }
      }
    }
  `
  type Node = {
    enabled: boolean
    notOlderThan: number
    maxDuration: number
    maxPageSize: number
  }
  const data = await graphql<{
    viewer: { zones: Array<{ settings: Record<string, Node> }> }
  }>(token, query, { zoneTag: zoneId })
  const zone = data.viewer.zones[0]
  if (!zone) throw new Error('Zone not found or token lacks access to this zone.')

  console.table(
    Object.entries(zone.settings).map(([name, s]) => ({
      dataset: name,
      enabled: s.enabled,
      notOlderThanDays: Math.round(s.notOlderThan / 86400),
      maxDurationDays: Math.round(s.maxDuration / 86400),
      maxPageSize: s.maxPageSize,
    })),
  )
  console.log(`
Tips:
  No filters → daily/hourly rollups (longest history)
  --filter / --group-by / aliases → AdaptiveGroups (attribute filters; shorter history)
`)
}

async function fetchDailyChunk(
  token: string,
  zoneId: string,
  start: string,
  end: string,
): Promise<MetricRow[]> {
  const query = `
    query($zoneTag: String, $start: Date, $end: Date) {
      viewer {
        zones(filter: { zoneTag: $zoneTag }) {
          httpRequests1dGroups(
            orderBy: [date_ASC]
            limit: 1000
            filter: { date_geq: $start, date_leq: $end }
          ) {
            dimensions { date }
            sum { requests pageViews bytes cachedBytes threats }
            uniq { uniques }
          }
        }
      }
    }
  `
  const data = await graphql<{
    viewer: {
      zones: Array<{
        httpRequests1dGroups: Array<{
          dimensions: { date: string }
          sum: MetricSums
          uniq: { uniques: number }
        }>
      }>
    }
  }>(token, query, { zoneTag: zoneId, start, end })
  const zone = data.viewer.zones[0]
  if (!zone) throw new Error('Zone not found or token lacks access to this zone.')
  return zone.httpRequests1dGroups.map((r) => ({
    period: r.dimensions.date,
    requests: r.sum.requests,
    pageViews: r.sum.pageViews,
    bytes: r.sum.bytes,
    cachedBytes: r.sum.cachedBytes,
    threats: r.sum.threats,
    uniques: r.uniq.uniques,
  }))
}

async function fetchHourlyChunk(
  token: string,
  zoneId: string,
  start: string,
  end: string,
): Promise<MetricRow[]> {
  const query = `
    query($zoneTag: String, $start: Time, $end: Time) {
      viewer {
        zones(filter: { zoneTag: $zoneTag }) {
          httpRequests1hGroups(
            orderBy: [datetime_ASC]
            limit: 1000
            filter: { datetime_geq: $start, datetime_lt: $end }
          ) {
            dimensions { datetime }
            sum { requests pageViews bytes cachedBytes threats }
            uniq { uniques }
          }
        }
      }
    }
  `
  const data = await graphql<{
    viewer: {
      zones: Array<{
        httpRequests1hGroups: Array<{
          dimensions: { datetime: string }
          sum: MetricSums
          uniq: { uniques: number }
        }>
      }>
    }
  }>(token, query, { zoneTag: zoneId, start, end })
  const zone = data.viewer.zones[0]
  if (!zone) throw new Error('Zone not found or token lacks access to this zone.')
  return zone.httpRequests1hGroups.map((r) => ({
    period: r.dimensions.datetime.replace(/\.\d{3}Z$/, 'Z'),
    requests: r.sum.requests,
    pageViews: r.sum.pageViews,
    bytes: r.sum.bytes,
    cachedBytes: r.sum.cachedBytes,
    threats: r.sum.threats,
    uniques: r.uniq.uniques,
  }))
}

async function fetchRollupStats(
  token: string,
  zoneId: string,
  start: string,
  end: string,
  interval: Interval,
): Promise<MetricRow[]> {
  if (interval === 'hour') {
    const byPeriod = new Map<string, MetricRow>()
    for (const chunk of datetimeChunks(start, end, CHUNK_DAYS.hour)) {
      for (const row of await fetchHourlyChunk(token, zoneId, chunk.start, chunk.end)) {
        byPeriod.set(row.period, row)
      }
    }
    return [...byPeriod.values()].sort((a, b) => a.period.localeCompare(b.period))
  }

  const byPeriod = new Map<string, MetricRow>()
  for (const chunk of dateChunks(start, end, CHUNK_DAYS.day)) {
    for (const row of await fetchDailyChunk(token, zoneId, chunk.start, chunk.end)) {
      byPeriod.set(row.period, row)
    }
  }
  const daily = [...byPeriod.values()].sort((a, b) => a.period.localeCompare(b.period))
  if (interval === 'day') return daily
  return rollupRows(daily, interval)
}

function buildAdaptiveDimensionSelection(timeDim: string, groupBy: string[]): string {
  const dims = new Set<string>([timeDim, ...groupBy])
  // Avoid selecting conflicting time dims
  const timeDims = new Set([
    'date',
    'datetime',
    'datetimeHour',
    'datetimeMinute',
    'datetimeFiveMinutes',
    'datetimeFifteenMinutes',
  ])
  const selected = [...dims].filter((d, _, arr) => {
    if (!timeDims.has(d)) return true
    return d === timeDim || !arr.includes(timeDim)
  })
  // Always keep explicit groupBy even if time-like
  for (const g of groupBy) selected.push(g)
  return [...new Set([timeDim, ...groupBy])].join('\n              ')
}

async function fetchAdaptiveChunk(
  token: string,
  zoneId: string,
  start: string,
  end: string,
  filters: Record<string, unknown>,
  groupBy: string[],
  interval: Interval,
  limit: number,
): Promise<MetricRow[]> {
  const timeDim = TIME_DIMENSION[interval]
  const dimSelection = buildAdaptiveDimensionSelection(timeDim, groupBy)
  const orderBy =
    groupBy.length > 0 ? `[${timeDim}_ASC, count_DESC]` : `[${timeDim}_ASC]`

  const query = `
    query($zoneTag: String, $filter: ZoneHttpRequestsAdaptiveGroupsFilter_InputObject, $limit: Int64!) {
      viewer {
        zones(filter: { zoneTag: $zoneTag }) {
          httpRequestsAdaptiveGroups(
            limit: $limit
            orderBy: ${orderBy}
            filter: $filter
          ) {
            count
            sum { edgeResponseBytes visits }
            dimensions {
              ${dimSelection}
            }
          }
        }
      }
    }
  `

  const filter = {
    ...filters,
    datetime_geq: start,
    datetime_lt: end,
  }

  const data = await graphql<{
    viewer: {
      zones: Array<{
        httpRequestsAdaptiveGroups: Array<{
          count: number
          sum: { edgeResponseBytes: number; visits: number }
          dimensions: Record<string, string | number | boolean | null>
        }>
      }>
    }
  }>(token, query, { zoneTag: zoneId, filter, limit })

  const zone = data.viewer.zones[0]
  if (!zone) throw new Error('Zone not found or token lacks access to this zone.')

  return zone.httpRequestsAdaptiveGroups.map((r) => {
    const dims = r.dimensions ?? {}
    const periodRaw = dims[timeDim]
    const period =
      typeof periodRaw === 'string'
        ? periodRaw.replace(/\.\d{3}Z$/, 'Z')
        : String(periodRaw ?? '')

    const group: Record<string, string | number | boolean | null> = {}
    for (const key of groupBy) {
      if (key === timeDim) continue
      group[key] = dims[key] ?? null
    }

    return {
      period,
      group: Object.keys(group).length ? group : undefined,
      requests: r.count,
      pageViews: r.sum.visits ?? 0,
      bytes: r.sum.edgeResponseBytes ?? 0,
      cachedBytes: 0,
      threats: 0,
      uniques: 0,
    }
  })
}

async function fetchAdaptiveStats(
  token: string,
  zoneId: string,
  start: string,
  end: string,
  filters: Record<string, unknown>,
  groupBy: string[],
  interval: Interval,
  limit: number,
): Promise<MetricRow[]> {
  const merged = new Map<string, MetricRow>()

  for (const chunk of datetimeChunks(start, end, CHUNK_DAYS.adaptive)) {
    const rows = await fetchAdaptiveChunk(
      token,
      zoneId,
      chunk.start,
      chunk.end,
      filters,
      groupBy,
      interval === 'week' || interval === 'month' ? 'day' : interval,
      limit,
    )
    for (const row of rows) {
      const key = `${row.period}|${JSON.stringify(row.group ?? {})}`
      const existing = merged.get(key)
      if (!existing) merged.set(key, row)
      else addSums(existing, pickSums(row))
    }
  }

  let rows = [...merged.values()].sort((a, b) => {
    const p = a.period.localeCompare(b.period)
    if (p !== 0) return p
    return JSON.stringify(a.group ?? {}).localeCompare(JSON.stringify(b.group ?? {}))
  })

  if (interval === 'week' || interval === 'month') {
    rows = rollupRows(rows, interval)
  }

  if (rows.length > limit) {
    rows = rows.slice(0, limit)
  }

  return rows
}

function formatCount(n: number): string {
  if (!Number.isFinite(n)) return '0'
  const abs = Math.abs(n)
  if (abs < 1000) return String(Math.round(n))
  const units = [
    { div: 1e12, suffix: 'T' },
    { div: 1e9, suffix: 'B' },
    { div: 1e6, suffix: 'M' },
    { div: 1e3, suffix: 'K' },
  ] as const
  for (const { div, suffix } of units) {
    if (abs >= div) {
      const value = n / div
      const digits = Math.abs(value) >= 100 ? 0 : Math.abs(value) >= 10 ? 1 : 2
      return `${value.toFixed(digits).replace(/\.0+$/, '').replace(/(\.\d*[1-9])0+$/, '$1')}${suffix}`
    }
  }
  return n.toLocaleString('en-US')
}

function formatCountExact(n: number): string {
  return n.toLocaleString('en-US')
}

function formatBytes(n: number): string {
  if (!Number.isFinite(n) || n === 0) return '0 B'
  if (Math.abs(n) < 1024) return `${Math.round(n)} B`
  const units = ['KB', 'MB', 'GB', 'TB', 'PB']
  let value = n / 1024
  let i = 0
  while (Math.abs(value) >= 1024 && i < units.length - 1) {
    value /= 1024
    i++
  }
  const digits = Math.abs(value) >= 100 ? 0 : Math.abs(value) >= 10 ? 1 : 2
  return `${value.toFixed(digits).replace(/\.0+$/, '').replace(/(\.\d*[1-9])0+$/, '$1')} ${units[i]}`
}

function formatPercent(n: number): string {
  if (!Number.isFinite(n)) return '0%'
  return `${n.toFixed(1)}%`
}

function shortDimName(field: string): string {
  for (const [alias, mapped] of Object.entries(GROUP_ALIASES)) {
    if (mapped === field) return alias
  }
  return field
}

function toDisplayRow(r: MetricRow, dataset: Dataset): Record<string, string> {
  const cacheHit = r.bytes > 0 && r.cachedBytes > 0 ? (r.cachedBytes / r.bytes) * 100 : 0
  const row: Record<string, string> = {
    period: r.period,
  }
  if (r.group) {
    for (const [k, v] of Object.entries(r.group)) {
      row[shortDimName(k)] = v == null ? '' : String(v)
    }
  }
  row.requests = formatCount(r.requests)
  row.pageViews = formatCount(r.pageViews)
  row.bandwidth = formatBytes(r.bytes)
  if (dataset === 'rollup') {
    row.cached = formatBytes(r.cachedBytes)
    row.cacheHit = formatPercent(cacheHit)
    row.uniques = formatCount(r.uniques)
    row.threats = formatCount(r.threats)
  }
  return row
}

function groupColumns(rows: MetricRow[]): string[] {
  const keys = new Set<string>()
  for (const r of rows) {
    if (r.group) for (const k of Object.keys(r.group)) keys.add(k)
  }
  return [...keys]
}

function toCsv(rows: MetricRow[], dataset: Dataset): string {
  const gcols = groupColumns(rows)
  const header = [
    'period',
    ...gcols.map(shortDimName),
    'requests',
    'pageViews',
    'bytes',
    'bandwidth',
    ...(dataset === 'rollup' ? ['cachedBytes', 'cached', 'cacheHit', 'uniques', 'threats'] : []),
  ]
  const lines = rows.map((r) => {
    const d = toDisplayRow(r, dataset)
    const vals = [
      r.period,
      ...gcols.map((k) => {
        const v = r.group?.[k]
        return v == null ? '' : String(v)
      }),
      r.requests,
      r.pageViews,
      r.bytes,
      d.bandwidth,
    ]
    if (dataset === 'rollup') {
      vals.push(
        r.cachedBytes,
        d.cached ?? '',
        d.cacheHit ?? '',
        r.uniques,
        r.threats,
      )
    }
    return vals
      .map((v) => {
        const s = String(v)
        return s.includes(',') || s.includes('"') ? `"${s.replace(/"/g, '""')}"` : s
      })
      .join(',')
  })
  return [header.join(','), ...lines].join('\n') + '\n'
}

function printSummary(
  rows: MetricRow[],
  start: string,
  end: string,
  zoneLabel: string,
  interval: Interval,
  dataset: Dataset,
  filters: Record<string, unknown>,
): void {
  const totals = rows.reduce((acc, r) => {
    addSums(acc, pickSums(r))
    return acc
  }, emptySums())
  const cacheHit = totals.bytes > 0 && totals.cachedBytes > 0 ? (totals.cachedBytes / totals.bytes) * 100 : 0
  const filterDesc =
    Object.keys(filters).length === 0
      ? '(none)'
      : Object.entries(filters)
          .map(([k, v]) => `${k}=${Array.isArray(v) ? v.join('|') : String(v)}`)
          .join(', ')

  const lines = [
    `Zone: ${zoneLabel}`,
    `Dataset: ${dataset === 'adaptive' ? 'httpRequestsAdaptiveGroups' : 'rollup (1d/1h)'}`,
    `Interval: ${interval}`,
    `Range: ${start} → ${end} (${formatCountExact(rows.length)} rows)`,
    `Filters: ${filterDesc}`,
    `Requests: ${formatCount(totals.requests)} (${formatCountExact(totals.requests)})`,
    `Page views / visits: ${formatCount(totals.pageViews)} (${formatCountExact(totals.pageViews)})`,
    `Bandwidth: ${formatBytes(totals.bytes)}`,
  ]
  if (dataset === 'rollup') {
    lines.push(
      `Cached: ${formatBytes(totals.cachedBytes)} (cache hit ${formatPercent(cacheHit)})`,
      `Uniques (sum of buckets): ${formatCount(totals.uniques)} (${formatCountExact(totals.uniques)})`,
      `Threats: ${formatCount(totals.threats)} (${formatCountExact(totals.threats)})`,
    )
  }
  console.error(lines.join('\n') + '\n')
}

function render(rows: MetricRow[], format: Format, dataset: Dataset): string {
  if (format === 'json') {
    return (
      JSON.stringify(
        rows.map((r) => ({
          ...toDisplayRow(r, dataset),
          raw: {
            requests: r.requests,
            pageViews: r.pageViews,
            bytes: r.bytes,
            cachedBytes: r.cachedBytes,
            threats: r.threats,
            uniques: r.uniques,
            group: r.group,
          },
        })),
        null,
        2,
      ) + '\n'
    )
  }
  if (format === 'csv') return toCsv(rows, dataset)
  return ''
}

async function main(): Promise<void> {
  const opts = parseArgs(process.argv.slice(2))
  if (opts.help) {
    printHelp()
    return
  }
  if (opts.listFilters) {
    printFilterHelp()
    return
  }
  if (opts.listDimensions) {
    printDimensionHelp()
    return
  }

  const token = requireToken()
  const { zoneId, name } = await resolveZoneId(token, opts.zone)
  const zoneLabel = name ? `${name} (${zoneId})` : zoneId

  if (opts.settings) {
    await fetchSettings(token, zoneId)
    return
  }

  const adaptive = usesAdaptive(opts)
  const dataset: Dataset = adaptive ? 'adaptive' : 'rollup'
  const { start, end } = resolveRange(opts, adaptive)

  if (adaptive) {
    const spanDays =
      (parseDateUTC(end).getTime() - parseDateUTC(start).getTime()) / 86400000 + 1
    if (spanDays > 32) {
      console.error(
        `Warning: attribute filters use AdaptiveGroups (often ~32 days retention). Your range is ${Math.round(spanDays)} days; older data may be missing. Check --settings.\n`,
      )
    }
  }

  const rows = adaptive
    ? await fetchAdaptiveStats(
        token,
        zoneId,
        start,
        end,
        opts.filters,
        opts.groupBy,
        opts.interval,
        opts.limit,
      )
    : await fetchRollupStats(token, zoneId, start, end, opts.interval)

  printSummary(rows, start, end, zoneLabel, opts.interval, dataset, opts.filters)

  if (opts.format === 'table') {
    console.table(rows.map((r) => toDisplayRow(r, dataset)))
    if (opts.out) {
      await writeFile(opts.out, toCsv(rows, dataset), 'utf8')
      console.error(`\nAlso wrote CSV to ${opts.out}`)
    }
    return
  }

  const body = render(rows, opts.format, dataset)
  if (opts.out) {
    await writeFile(opts.out, body, 'utf8')
    console.error(`Wrote ${opts.format} to ${opts.out}`)
  } else {
    process.stdout.write(body)
  }
}

try {
  await main()
} catch (error) {
  const message = error instanceof Error ? error.message : String(error)
  console.error(`Error: ${message}`)
  process.exit(1)
}
