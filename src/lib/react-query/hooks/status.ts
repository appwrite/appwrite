import { useEffect, useState } from 'react'
import { queryOptions, useQuery } from '@tanstack/react-query'

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

type StatusReportItem = {
  type: 'status_report'
  attributes?: {
    title?: string
    report_type?: string
    aggregate_state?: string
    starts_at?: string | null
    ends_at?: string | null
  }
}

type StatusResourceItem = {
  type: 'status_page_resource'
  attributes?: {
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
  aggregateState: AppwriteCloudAggregateState
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

export async function fetchAppwriteCloudStatus(): Promise<AppwriteCloudStatusSummary> {
  const response = await fetch(APPWRITE_CLOUD_STATUS_URL)
  if (!response.ok) {
    throw new Error(`Failed to fetch Appwrite Cloud status: ${response.status}`)
  }

  const payload = (await response.json()) as StatusPageResponse
  const aggregateState = normalizeAggregateState(
    payload.data?.attributes?.aggregate_state,
  )

  const reports =
    payload.included
      ?.filter(
        (item): item is StatusReportItem => item.type === 'status_report',
      )
      .map((report) => ({
        title: report.attributes?.title?.trim() || 'Ongoing Appwrite Cloud issue',
        reportType: normalizeReportType(report.attributes?.report_type),
        aggregateState: normalizeReportAggregateState(
          report.attributes?.aggregate_state,
        ),
        startsAt: report.attributes?.starts_at,
        endsAt: report.attributes?.ends_at,
        sortStartsAt: report.attributes?.starts_at
          ? Date.parse(report.attributes.starts_at)
          : 0,
      }))
      .sort((left, right) => right.sortStartsAt - left.sortStartsAt) ?? []

  const servicesMap = new Map<string, AppwriteCloudServiceState>()
  payload.included
    ?.filter(
      (item): item is StatusResourceItem => item.type === 'status_page_resource',
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
    aggregateState === 'operational'
      ? undefined
      : activeReports.find((report) => report.aggregateState === aggregateState) ??
        activeReports.find((report) => report.aggregateState !== 'maintenance') ??
        activeReports[0]

  return {
    aggregateState,
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
