import { useEffect, useState } from 'react'
import { queryOptions, useQuery } from '@tanstack/react-query'

import { formatStatusAffectedRegionsLine } from '@/lib/cloud-status-copy'

const APPWRITE_CLOUD_STATUS_URL = 'https://status.appwrite.online/index.json'
const STATUS_REFRESH_INTERVAL = 60 * 1000

export type AppwriteCloudAggregateState =
  | 'operational'
  | 'degraded'
  | 'downtime'
  | 'maintenance'

export type AppwriteCloudServiceState =
  | AppwriteCloudAggregateState
  | 'not_monitored'

type StatusReportAggregateState =
  | AppwriteCloudAggregateState
  | 'resolved'
  | 'not_monitored'

type StatusReportType = 'automatic' | 'maintenance' | 'manual'

type StatusReportAffectedResource = {
  status_page_resource_id?: string | number
  status?: string
}

type StatusReportItem = {
  type: 'status_report'
  id?: string
  attributes?: {
    title?: string
    report_type?: string
    aggregate_state?: string
    starts_at?: string | null
    ends_at?: string | null
    affected_resources?: StatusReportAffectedResource[]
  }
}

type StatusPageSectionItem = {
  type: 'status_page_section'
  id?: string
  attributes?: {
    name?: string
  }
}

type StatusResourceItem = {
  type: 'status_page_resource'
  id?: string | number
  attributes?: {
    status_page_section_id?: number | string
    public_name?: string
    status?: string
  }
}

type StatusPageResponse = {
  data?: {
    attributes?: {
      aggregate_state?: string
    }
  }
  included?: Array<
    | StatusReportItem
    | StatusResourceItem
    | {
        type: string
      }
  >
}

export type AppwriteCloudServiceSummary = {
  name: string
  status: AppwriteCloudServiceState
}

export type AppwriteCloudStatusSummary = {
  /** Raw aggregate state from the status page API. */
  aggregateState: AppwriteCloudAggregateState
  /**
   * Console banner/loader alert state: worst state among core services only.
   * Stays operational when non-core monitors (e.g. Support) are the sole issue.
   */
  consoleAlertState: AppwriteCloudAggregateState
  /**
   * Affected regions (and similar) when the page is not fully operational.
   * Uses the active incident report when present, otherwise live regional monitors.
   */
  regionsLine?: string
  activeReport?: {
    title: string
    reportType: StatusReportType
    aggregateState: StatusReportAggregateState
    startsAt?: string | null
    endsAt?: string | null
  }
  services: AppwriteCloudServiceSummary[]
}

export const DEFAULT_APPWRITE_CLOUD_SERVICE_NAMES = [
  'Main',
  'Documentation',
  'Console',
  'API',
  'Auth',
  'Databases',
  'Functions',
  'Sites',
  'Storage',
  'Messaging',
  'MCP',
  'Support',
  'DNS ns1.appwrite.zone',
  'DNS ns2.appwrite.zone',
] as const

/**
 * Non-core monitors (Support, Documentation, etc.) should not trigger the global
 * console status banner when they are the only services affected.
 */
export const STATUS_BANNER_EXCLUDED_SERVICE_NAMES = new Set<string>([
  'Support',
  'Documentation',
])

function normalizeServiceState(
  value: string | undefined,
): AppwriteCloudServiceState {
  switch (value) {
    case 'degraded':
    case 'downtime':
    case 'maintenance':
    case 'not_monitored':
      return value
    default:
      return 'operational'
  }
}

function getServiceStateWeight(state: AppwriteCloudServiceState): number {
  switch (state) {
    case 'downtime':
      return 4
    case 'degraded':
      return 3
    case 'maintenance':
      return 2
    case 'operational':
      return 1
    default:
      return 0
  }
}

function getAggregateStateWeight(
  state: AppwriteCloudAggregateState,
): number {
  return getServiceStateWeight(state)
}

export function computeConsoleAlertState(
  services: AppwriteCloudServiceSummary[],
): AppwriteCloudAggregateState {
  let worst: AppwriteCloudAggregateState = 'operational'

  for (const service of services) {
    if (STATUS_BANNER_EXCLUDED_SERVICE_NAMES.has(service.name)) continue
    if (service.status === 'operational' || service.status === 'not_monitored') {
      continue
    }

    const nextState = service.status as AppwriteCloudAggregateState
    if (getAggregateStateWeight(nextState) > getAggregateStateWeight(worst)) {
      worst = nextState
    }
  }

  return worst
}

function compareServiceNames(left: string, right: string) {
  const leftIndex = DEFAULT_APPWRITE_CLOUD_SERVICE_NAMES.indexOf(
    left as (typeof DEFAULT_APPWRITE_CLOUD_SERVICE_NAMES)[number],
  )
  const rightIndex = DEFAULT_APPWRITE_CLOUD_SERVICE_NAMES.indexOf(
    right as (typeof DEFAULT_APPWRITE_CLOUD_SERVICE_NAMES)[number],
  )

  if (leftIndex >= 0 && rightIndex >= 0) return leftIndex - rightIndex
  if (leftIndex >= 0) return -1
  if (rightIndex >= 0) return 1
  return left.localeCompare(right)
}

function normalizeAggregateState(
  value: string | undefined,
): AppwriteCloudAggregateState {
  switch (value) {
    case 'degraded':
    case 'downtime':
    case 'maintenance':
      return value
    default:
      return 'operational'
  }
}

function normalizeReportType(value: string | undefined): StatusReportType {
  switch (value) {
    case 'automatic':
    case 'maintenance':
      return value
    default:
      return 'manual'
  }
}

function normalizeReportAggregateState(
  value: string | undefined,
): StatusReportAggregateState {
  switch (value) {
    case 'degraded':
    case 'downtime':
    case 'maintenance':
    case 'resolved':
    case 'not_monitored':
      return value
    default:
      return 'resolved'
  }
}

function parseTimestamp(value?: string | null): number | null {
  if (!value) return null
  const timestamp = Date.parse(value)
  return Number.isNaN(timestamp) ? null : timestamp
}

const CLOUD_SERVICES_SECTION = /^Cloud services - (.+)$/i

function buildCloudRegionSectionMap(
  included: StatusPageResponse['included'],
): { sectionIdToRegionCode: Map<number, string>; allRegionCodes: string[] } {
  const sectionIdToRegionCode = new Map<number, string>()
  for (const item of included ?? []) {
    if (item.type !== 'status_page_section') continue
    const section = item as StatusPageSectionItem
    const name = section.attributes?.name?.trim()
    if (!name) continue
    const match = name.match(CLOUD_SERVICES_SECTION)
    if (!match) continue
    const code = match[1].trim()
    const sectionId = Number(section.id)
    if (!Number.isFinite(sectionId)) continue
    sectionIdToRegionCode.set(sectionId, code)
  }
  const allRegionCodes = [...new Set(sectionIdToRegionCode.values())].sort(
    (a, b) => a.localeCompare(b),
  )
  return { sectionIdToRegionCode, allRegionCodes }
}

function buildResourceRegionIndex(
  included: StatusPageResponse['included'],
): Map<string, { sectionId: number; status: AppwriteCloudServiceState }> {
  const resourceById = new Map<
    string,
    { sectionId: number; status: AppwriteCloudServiceState }
  >()
  for (const item of included ?? []) {
    if (item.type !== 'status_page_resource') continue
    const resource = item as StatusResourceItem
    if (resource.id === undefined || resource.id === null) continue
    const rawSectionId = resource.attributes?.status_page_section_id
    if (rawSectionId === undefined || rawSectionId === null) continue
    const sectionId =
      typeof rawSectionId === 'number' ? rawSectionId : Number(rawSectionId)
    if (!Number.isFinite(sectionId)) continue
    resourceById.set(String(resource.id), {
      sectionId,
      status: normalizeServiceState(resource.attributes?.status),
    })
  }
  return resourceById
}

function regionCodesFromAffectedResources(
  affected: StatusReportAffectedResource[] | undefined,
  sectionIdToRegionCode: Map<number, string>,
  resourceById: Map<string, { sectionId: number; status: AppwriteCloudServiceState }>,
): string[] {
  const refs = Array.isArray(affected) ? affected : []
  const unresolved = refs.filter((r) => r.status !== 'resolved')
  const toScan = unresolved.length > 0 ? unresolved : refs
  const set = new Set<string>()
  for (const ref of toScan) {
    const resourceId = ref.status_page_resource_id
    if (resourceId === undefined || resourceId === null) continue
    const res = resourceById.get(String(resourceId))
    if (!res) continue
    const code = sectionIdToRegionCode.get(res.sectionId)
    if (code) set.add(code)
  }
  return [...set].sort((a, b) => a.localeCompare(b))
}

function regionCodesFromLiveNonOperationalCloud(
  sectionIdToRegionCode: Map<number, string>,
  resourceById: Map<string, { sectionId: number; status: AppwriteCloudServiceState }>,
): string[] {
  const set = new Set<string>()
  for (const res of resourceById.values()) {
    if (res.status === 'operational' || res.status === 'not_monitored') continue
    const code = sectionIdToRegionCode.get(res.sectionId)
    if (code) set.add(code)
  }
  return [...set].sort((a, b) => a.localeCompare(b))
}

function emptyAppwriteCloudStatusSummary(): AppwriteCloudStatusSummary {
  return {
    aggregateState: 'operational',
    consoleAlertState: 'operational',
    services: [],
  }
}

function buildStatusRegionsLine(
  title: string,
  affected: StatusReportAffectedResource[] | undefined,
  sectionIdToRegionCode: Map<number, string>,
  allRegionCodes: string[],
  resourceById: Map<string, { sectionId: number; status: AppwriteCloudServiceState }>,
): string | undefined {
  let codes = regionCodesFromAffectedResources(
    affected,
    sectionIdToRegionCode,
    resourceById,
  )
  if (codes.length === 0) {
    codes = regionCodesFromLiveNonOperationalCloud(
      sectionIdToRegionCode,
      resourceById,
    )
  }
  const titleSaysAll = /\ball regions\b/i.test(title)
  const coversAll =
    allRegionCodes.length > 0 &&
    codes.length === allRegionCodes.length &&
    allRegionCodes.every((c) => codes.includes(c))
  const allRegionsAffected = titleSaysAll || coversAll
  return formatStatusAffectedRegionsLine(allRegionsAffected, codes)
}

function parseAppwriteStatusPayload(
  payload: StatusPageResponse,
): AppwriteCloudStatusSummary {
  const included = Array.isArray(payload.included) ? payload.included : []

  const aggregateState = normalizeAggregateState(
    payload.data?.attributes?.aggregate_state,
  )

  const { sectionIdToRegionCode, allRegionCodes } =
    buildCloudRegionSectionMap(included)
  const resourceById = buildResourceRegionIndex(included)

  const reports =
    included
      .filter(
        (item): item is StatusReportItem => item.type === 'status_report',
      )
      .map((report) => ({
        title:
          report.attributes?.title?.trim() || 'Ongoing Appwrite Cloud issue',
        reportType: normalizeReportType(report.attributes?.report_type),
        aggregateState: normalizeReportAggregateState(
          report.attributes?.aggregate_state,
        ),
        startsAt: report.attributes?.starts_at,
        endsAt: report.attributes?.ends_at,
        sortStartsAt: report.attributes?.starts_at
          ? Date.parse(report.attributes.starts_at)
          : 0,
        affectedResources: Array.isArray(report.attributes?.affected_resources)
          ? report.attributes.affected_resources
          : [],
      }))
      .sort((left, right) => right.sortStartsAt - left.sortStartsAt)

  const servicesMap = new Map<string, AppwriteCloudServiceState>()
  included
    .filter(
      (item): item is StatusResourceItem =>
        item.type === 'status_page_resource',
    )
    .forEach((resource) => {
      const serviceName = resource.attributes?.public_name?.trim()
      if (!serviceName) return

      const nextState = normalizeServiceState(resource.attributes?.status)
      const currentState = servicesMap.get(serviceName)

      if (
        !currentState ||
        getServiceStateWeight(nextState) > getServiceStateWeight(currentState)
      ) {
        servicesMap.set(serviceName, nextState)
      }
    })

  const services = Array.from(servicesMap.entries())
    .map(([name, status]) => ({ name, status }))
    .sort((left, right) => compareServiceNames(left.name, right.name))

  const consoleAlertState = computeConsoleAlertState(services)

  const now = Date.now()
  const activeReports = reports.filter((report) => {
    if (report.aggregateState === 'resolved') return false

    const startsAt = parseTimestamp(report.startsAt)
    const endsAt = parseTimestamp(report.endsAt)

    if (startsAt !== null && startsAt > now) return false
    if (endsAt !== null && endsAt <= now) return false

    return true
  })

  const activeReport =
    consoleAlertState === 'operational'
      ? undefined
      : (activeReports.find(
          (report) => report.aggregateState === consoleAlertState,
        ) ??
        activeReports.find(
          (report) => report.aggregateState !== 'maintenance',
        ) ??
        activeReports[0])

  const regionsLine =
    consoleAlertState === 'operational'
      ? undefined
      : buildStatusRegionsLine(
          activeReport?.title ?? '',
          activeReport?.affectedResources,
          sectionIdToRegionCode,
          allRegionCodes,
          resourceById,
        )

  return {
    aggregateState,
    consoleAlertState,
    regionsLine,
    activeReport: activeReport
      ? {
          title: activeReport.title,
          reportType: activeReport.reportType,
          aggregateState: activeReport.aggregateState,
          startsAt: activeReport.startsAt,
          endsAt: activeReport.endsAt,
        }
      : undefined,
    services,
  }
}

export async function fetchAppwriteCloudStatus(): Promise<AppwriteCloudStatusSummary> {
  const response = await fetch(APPWRITE_CLOUD_STATUS_URL)
  if (!response.ok) {
    throw new Error(`Failed to fetch Appwrite Cloud status: ${response.status}`)
  }

  let payload: unknown
  try {
    payload = await response.json()
  } catch {
    return emptyAppwriteCloudStatusSummary()
  }

  if (!payload || typeof payload !== 'object') {
    return emptyAppwriteCloudStatusSummary()
  }

  try {
    return parseAppwriteStatusPayload(payload as StatusPageResponse)
  } catch {
    return emptyAppwriteCloudStatusSummary()
  }
}

export function appwriteCloudStatusQueryOptions(enabled = true) {
  return queryOptions({
    queryKey: ['status-page', 'appwrite-cloud'],
    queryFn: fetchAppwriteCloudStatus,
    enabled,
    meta: {
      skipInitialLoader: true,
    },
    staleTime: STATUS_REFRESH_INTERVAL,
    gcTime: STATUS_REFRESH_INTERVAL * 5,
    refetchInterval: STATUS_REFRESH_INTERVAL,
    refetchOnWindowFocus: true,
    retry: 1,
  })
}

export function useAppwriteCloudStatus(enabled = true) {
  const [hasMounted, setHasMounted] = useState(false)

  useEffect(() => {
    setHasMounted(true)
  }, [])

  return useQuery(appwriteCloudStatusQueryOptions(enabled && hasMounted))
}
