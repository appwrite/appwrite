import type { QueryClient, QueryKey } from '@tanstack/react-query'
import type { DistributionLog } from '@/lib/appwrite/distribution'
import { insertLog, type LogPages } from '@/lib/distribution/logs'
import {
  DISTRIBUTION_OPEN_RELEASE_STATUSES,
  DISTRIBUTION_READY_ARTIFACT_STATUS,
  syncDistributionBuildLogs,
} from '@/lib/react-query/hooks/distribution'
import { captureExceptionWithContext } from '@/lib/sentry/report-error'
import { REALTIME_EVENTS } from './constants'

type Row = Record<string, unknown>

type Resource = Row & { $id: string }

const ACTIONS = ['create', 'update', 'delete']

const SERVICES = [
  REALTIME_EVENTS.DISTRIBUTION_APPS_ANY,
  REALTIME_EVENTS.DISTRIBUTION_CONNECTIONS_ANY,
  REALTIME_EVENTS.DISTRIBUTION_SIGNING_IDENTITIES_ANY,
].map((name) => name.slice(0, -1))

const DOCUMENTS = {
  builds: {
    detail: 'distribution-build',
    list: 'distribution-builds',
    field: 'builds',
  },
  artifacts: {
    detail: 'distribution-artifact',
    list: 'distribution-artifacts',
    field: 'artifacts',
  },
  releases: {
    detail: 'distribution-release',
    list: 'distribution-releases',
    field: 'releases',
  },
  submissions: {
    detail: 'distribution-submission',
    list: 'distribution-submissions',
    field: 'submissions',
  },
} as const

type DocumentType = keyof typeof DOCUMENTS

type Filter = {
  segment: string
  stale: (data: unknown, document: Resource, queryKey: QueryKey) => boolean
}

function listFilter(
  segment: string,
  belongs: (document: Resource, queryKey: QueryKey) => boolean | null,
): Filter {
  return {
    segment,
    stale: (data, document, queryKey) => {
      if (!Array.isArray(data)) return false
      const expected = belongs(document, queryKey)
      if (expected === null) return false
      const listed = data.some((row) => isRow(row) && row.$id === document.$id)
      return listed !== expected
    },
  }
}

function fieldBelongs(field: string) {
  return (document: Resource, queryKey: QueryKey): boolean | null =>
    typeof document[field] === 'string' ? document[field] === queryKey[4] : null
}

const latestFilter: Filter = {
  segment: 'latest',
  stale: (data, document, queryKey) => {
    if (document.targetId !== queryKey[4]) return false
    if (data === null) return true
    if (!isRow(data) || data.$id === document.$id) return false
    return text(document.$createdAt, '') > text(data.$createdAt, '')
  },
}

const FILTERS: Record<DocumentType, Filter[]> = {
  builds: [listFilter('release', fieldBelongs('releaseId')), latestFilter],
  artifacts: [
    listFilter('ready', (document, queryKey) => {
      if (typeof document.status !== 'string') return null
      const target = fieldBelongs('targetId')(document, queryKey)
      return (
        document.status === DISTRIBUTION_READY_ARTIFACT_STATUS &&
        target !== false
      )
    }),
  ],
  releases: [
    listFilter('open', (document) =>
      typeof document.status === 'string'
        ? DISTRIBUTION_OPEN_RELEASE_STATUSES.some(
            (status) => status === document.status,
          )
        : null,
    ),
  ],
  submissions: [listFilter('release', fieldBelongs('releaseId')), latestFilter],
}

function isDistributionEvent(event: string): boolean {
  return SERVICES.some((prefix) => event.startsWith(prefix))
}

export function isDistributionRealtimeSignal(events: string[]): boolean {
  return events.some(isDistributionEvent)
}

// The server also sends action-less parents and wildcard permutations of the
// event; only the most specific one describes what happened.
function selectEvent(events: string[]): string[] | null {
  let selected: string[] | null = null
  for (const event of events) {
    if (event.includes('*') || !isDistributionEvent(event)) continue
    const segments = event.split('.')
    if (!ACTIONS.includes(segments[segments.length - 1])) continue
    if (!selected || segments.length > selected.length) selected = segments
  }
  return selected
}

function isRow(value: unknown): value is Row {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isDocument(value: unknown): value is Resource {
  return isRow(value) && typeof value.$id === 'string' && value.$id !== ''
}

function isDocumentType(type: string): type is DocumentType {
  return Object.hasOwn(DOCUMENTS, type)
}

function refresh(queryClient: QueryClient, queryKey: QueryKey): void {
  queryClient.removeQueries({ queryKey, type: 'inactive' })
  void queryClient.invalidateQueries({ queryKey })
}

function refreshFiltered(
  queryClient: QueryClient,
  listKey: QueryKey,
  type: DocumentType,
  document: Resource,
): void {
  const cache = queryClient.getQueryCache()
  for (const { segment, stale } of FILTERS[type]) {
    for (const query of cache.findAll({ queryKey: [...listKey, segment] })) {
      if (stale(query.state.data, document, query.queryKey)) {
        refresh(queryClient, query.queryKey)
      }
    }
  }
}

function mergeRows(rows: unknown[], document: Resource): unknown[] | null {
  const index = rows.findIndex((row) => isRow(row) && row.$id === document.$id)
  if (index < 0) return null
  const next = [...rows]
  next[index] = { ...(rows[index] as Row), ...document }
  return next
}

function mergeDocument(
  queryClient: QueryClient,
  queryKey: QueryKey,
  field: string,
  document: Resource,
): boolean {
  let merged = false
  queryClient.setQueriesData({ queryKey }, (old: unknown) => {
    if (Array.isArray(old)) {
      const rows = mergeRows(old, document)
      if (!rows) return old
      merged = true
      return rows
    }
    if (!isRow(old)) return old
    const list = old[field]
    if (Array.isArray(list)) {
      const rows = mergeRows(list, document)
      if (!rows) return old
      merged = true
      return { ...old, [field]: rows }
    }
    if (old.$id !== document.$id) return old
    merged = true
    return { ...old, ...document }
  })
  return merged
}

function text(value: unknown, fallback: string): string {
  return typeof value === 'string' ? value : fallback
}

function count(value: unknown, fallback: number): number {
  const number = typeof value === 'string' ? Number(value) : value
  return typeof number === 'number' && Number.isFinite(number)
    ? number
    : fallback
}

function sequence(value: unknown): string {
  if (typeof value === 'string') return value
  return typeof value === 'number' ? String(value) : ''
}

function handleApp(
  queryClient: QueryClient,
  projectId: string,
  appId: string,
  action: string,
  payload: Row | null,
): void {
  const detailKey = ['distribution-app', projectId, appId]
  const listKey = ['distribution-apps', projectId]
  if (action === 'update' && isDocument(payload)) {
    mergeDocument(queryClient, detailKey, 'apps', payload)
    mergeDocument(queryClient, listKey, 'apps', payload)
    return
  }
  if (action === 'update') refresh(queryClient, detailKey)
  refresh(queryClient, listKey)
}

function handleLog(
  queryClient: QueryClient,
  projectId: string,
  appId: string,
  buildId: string,
  logId: string,
  payload: Row | null,
): void {
  const queryKey = ['distribution-build-logs', projectId, appId, buildId]
  const fields = payload ?? {}
  const chunk = count(fields.chunk, 0)
  const chunks = count(fields.chunks, 1)
  const log = {
    $id: text(fields.$id, '') || logId,
    $createdAt: text(fields.$createdAt, '') || new Date().toISOString(),
    buildId: text(fields.buildId, '') || buildId,
    sequence: sequence(fields.sequence),
    chunk,
    stage: text(fields.stage, ''),
    content: text(fields.content, ''),
  }
  queryClient.setQueryData<LogPages<DistributionLog>>(queryKey, (old) =>
    insertLog(old, log),
  )
  if (chunk === 0 && chunks <= 1) return
  queryClient.removeQueries({ queryKey, exact: true, type: 'inactive' })
  syncDistributionBuildLogs(queryClient, projectId, appId, buildId).catch(
    (error: unknown) =>
      captureExceptionWithContext(error, {
        projectId,
        source: 'distribution-build-logs-sync',
      }),
  )
}

function handleDocument(
  queryClient: QueryClient,
  projectId: string,
  appId: string,
  type: DocumentType,
  documentId: string,
  action: string,
  payload: Row | null,
): void {
  const { detail, list, field } = DOCUMENTS[type]
  const detailKey = [detail, projectId, appId, documentId]
  const listKey = [list, projectId, appId]

  if (action === 'delete') {
    queryClient.removeQueries({ queryKey: detailKey })
    if (type === 'builds') {
      queryClient.removeQueries({
        queryKey: ['distribution-build-logs', projectId, appId, documentId],
      })
    }
    refresh(queryClient, listKey)
  } else if (isDocument(payload)) {
    mergeDocument(queryClient, detailKey, field, payload)
    const merged = mergeDocument(queryClient, listKey, field, payload)
    if (action === 'create' || !merged) refresh(queryClient, listKey)
    else refreshFiltered(queryClient, listKey, type, payload)
  } else {
    refresh(queryClient, detailKey)
    refresh(queryClient, listKey)
  }

  if (type !== 'submissions') return
  const releaseId = text(payload?.releaseId, '')
  refresh(
    queryClient,
    releaseId
      ? ['distribution-release', projectId, appId, releaseId]
      : ['distribution-release', projectId, appId],
  )
  refresh(queryClient, ['distribution-releases', projectId, appId])
}

export function handleDistributionRealtimeEvents(
  queryClient: QueryClient,
  projectId: string,
  events: string[],
  payload: Row | null,
): void {
  const segments = selectEvent(events)
  if (!segments) return
  const [service, id, type, documentId] = segments
  const action = segments[segments.length - 1]

  if (service === 'distributionConnections') {
    refresh(queryClient, ['distribution-connections', projectId])
    refresh(queryClient, ['distribution-check', projectId])
    return
  }
  if (service === 'distributionSigningIdentities') {
    refresh(queryClient, ['distribution-signing-identities', projectId])
    refresh(queryClient, ['distribution-check', projectId])
    return
  }
  if (service !== 'distributionApps') return

  if (segments.length === 3) {
    handleApp(queryClient, projectId, id, action, payload)
    return
  }
  if (segments.length === 7) {
    if (type === 'builds' && segments[4] === 'logs' && action === 'create') {
      handleLog(queryClient, projectId, id, documentId, segments[5], payload)
    }
    return
  }
  if (segments.length !== 5) return
  if (type === 'targets') {
    refresh(queryClient, ['distribution-targets', projectId, id])
    refresh(queryClient, ['distribution-check', projectId, id])
    return
  }
  if (isDocumentType(type)) {
    handleDocument(
      queryClient,
      projectId,
      id,
      type,
      documentId,
      action,
      payload,
    )
  }
}
