/**
 * Recently viewed project resources for the Command Center.
 *
 * Separate from browser/navigation history: tracks actual resources (name,
 * kind icon, breadcrumbs), newest first, with duplicates removed.
 */

import type { QueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { isDatabaseRouteKind } from '@/lib/database-routes'
import {
  isMongoEngine,
  isMysqlEngine,
  isPostgresEngine,
} from '@/lib/databases/native-database-engines'
import { getDatabaseTypeDisplayLabel } from '@/lib/databases/database-type-display'
import { isStoragePlaceholderBucketId } from '@/lib/storage-routes'
import { getMessageSearchLabel } from './resource-search'
import type {
  ProjectResourceKind,
  ProjectResourceSection,
} from './resource-search'

export const RECENT_RESOURCES_STORAGE_KEY = 'console.recentResources'
export const RECENT_RESOURCES_MAX_STORED = 10
export const RECENT_RESOURCES_MAX_SHOWN = 5

export type RecentDatabaseIconHints = {
  apiType?: string
  engine?: string
}

export interface RecentResource {
  /** Stable identity for deduplication (project + kind + resource id). */
  key: string
  kind: ProjectResourceKind
  name: string
  /** Breadcrumb segments shown under the name (service / parent trail). */
  breadcrumbs: string[]
  projectId: string
  section: ProjectResourceSection
  resourceId: string
  /** Canonical path to open the resource. */
  href: string
  viewedAt: number
  /** Product database API type (tablesdb, documentsdb, vectorsdb). */
  databaseApiType?: string
  /** Native dedicated database engine (postgres, mysql, mongo). */
  databaseEngine?: string
  /** Site build framework key (e.g. nextjs, react) for FrameworkIcon. */
  siteFramework?: string
}

type ParsedResourceRef = {
  projectId: string
  kind: ProjectResourceKind
  section: ProjectResourceSection
  resourceId: string
  href: string
  breadcrumbs: string[]
}

const RESERVED_SEGMENTS = new Set([
  'create',
  'templates',
  'usage',
  'settings',
  'security',
  'activity',
  'domains',
  'deployments',
  'logs',
  'variables',
  'executions',
  'overview',
  'monitor',
  'backups',
  'connections',
  'visualizer',
  'sql',
  'export-import',
  'db-security',
  'db-settings',
  'browser',
])

function resourceKey(
  projectId: string,
  kind: ProjectResourceKind,
  resourceId: string,
): string {
  return `${projectId}:${kind}:${resourceId}`
}

function named(value: unknown): string | undefined {
  if (!value || typeof value !== 'object') return undefined
  const record = value as { name?: unknown; email?: unknown }
  if (typeof record.name === 'string' && record.name.trim()) {
    return record.name.trim()
  }
  if (typeof record.email === 'string' && record.email.trim()) {
    return record.email.trim()
  }
  return undefined
}

/** Parse database icon hints from a stored recent-resource href. */
export function parseDatabaseIconHintsFromHref(
  href: string,
): RecentDatabaseIconHints {
  const parts = href.split('/').filter(Boolean)
  const databasesIndex = parts.indexOf('databases')
  if (databasesIndex === -1) return {}

  const segment = parts[databasesIndex + 1]
  if (!segment) return {}

  if (isPostgresEngine(segment)) return { engine: 'postgres' }
  if (isMysqlEngine(segment)) return { engine: 'mysql' }
  if (isMongoEngine(segment)) return { engine: 'mongo' }
  if (isDatabaseRouteKind(segment)) return { apiType: segment }

  return {}
}

function findDedicatedDatabaseInCache(
  queryClient: QueryClient,
  projectId: string,
  resourceId: string,
): Models.DedicatedDatabase | undefined {
  const entries = queryClient.getQueriesData({
    queryKey: ['dedicated-databases', 'project', projectId],
  })
  for (const [, data] of entries) {
    if (!data || typeof data !== 'object') continue
    const list = (data as { databases?: Models.DedicatedDatabase[] }).databases
    if (!list) continue
    const hit = list.find((item) => item.$id === resourceId)
    if (hit) return hit
  }
  return undefined
}

function hintsFromDedicatedDatabase(
  db: Models.DedicatedDatabase,
): RecentDatabaseIconHints {
  const api = db.api?.toLowerCase().trim() ?? ''
  if (api === 'tablesdb' || api === 'documentsdb' || api === 'vectorsdb') {
    return { apiType: api }
  }

  const engine = db.engine?.toLowerCase().trim() ?? ''
  if (isPostgresEngine(engine)) return { engine: 'postgres' }
  if (isMysqlEngine(engine)) return { engine: 'mysql' }
  if (isMongoEngine(engine)) return { engine: 'mongo' }
  if (engine) return { engine }

  return {}
}

/** Resolve database icon hints for a recent resource (href first, then cache). */
export function resolveRecentDatabaseIconHints(
  queryClient: QueryClient,
  ref: Pick<ParsedResourceRef, 'projectId' | 'resourceId' | 'href' | 'kind'>,
): RecentDatabaseIconHints {
  if (ref.kind !== 'database') return {}

  const fromHref = parseDatabaseIconHintsFromHref(ref.href)
  if (fromHref.apiType || fromHref.engine) return fromHref

  const { projectId, resourceId } = ref

  const postgresDb = queryClient.getQueryData([
    'postgres-database',
    'project',
    projectId,
    resourceId,
  ])
  if (postgresDb) return { engine: 'postgres' }

  const dedicatedDb = findDedicatedDatabaseInCache(
    queryClient,
    projectId,
    resourceId,
  )
  if (dedicatedDb) return hintsFromDedicatedDatabase(dedicatedDb)

  const productDb = queryClient.getQueryData([
    'database',
    'project',
    projectId,
    resourceId,
  ]) as Models.Database | undefined
  if (productDb?.type) {
    return { apiType: String(productDb.type) }
  }

  const apiTypeFromList = findInListCache<Models.Database>(
    queryClient,
    ['databases', 'project', projectId],
    (item) => item.$id === resourceId,
    (item) => (item.type ? String(item.type) : undefined),
  )
  if (apiTypeFromList) return { apiType: apiTypeFromList }

  return {}
}

export function getRecentResourceDatabaseIconHints(
  entry: Pick<
    RecentResource,
    'kind' | 'href' | 'databaseApiType' | 'databaseEngine'
  >,
): RecentDatabaseIconHints {
  if (entry.kind !== 'database') return {}
  if (entry.databaseApiType || entry.databaseEngine) {
    return {
      apiType: entry.databaseApiType,
      engine: entry.databaseEngine,
    }
  }
  return parseDatabaseIconHintsFromHref(entry.href)
}

function getSiteFrameworkFromModel(site: unknown): string | undefined {
  if (!site || typeof site !== 'object') return undefined
  const record = site as {
    framework?: unknown
    buildFramework?: unknown
    buildFrameworkId?: unknown
  }
  for (const key of [
    'framework',
    'buildFramework',
    'buildFrameworkId',
  ] as const) {
    const value = record[key]
    if (typeof value === 'string' && value.trim()) return value.trim()
  }
  return undefined
}

/** Resolve site framework key for a recent resource from the React Query cache. */
export function resolveRecentSiteFramework(
  queryClient: QueryClient,
  ref: Pick<ParsedResourceRef, 'projectId' | 'resourceId' | 'kind'>,
): string | undefined {
  if (ref.kind !== 'site') return undefined

  const { projectId, resourceId } = ref

  const fromDirect = getSiteFrameworkFromModel(
    queryClient.getQueryData(['site', 'project', projectId, resourceId]),
  )
  if (fromDirect) return fromDirect

  return findInListCache(
    queryClient,
    ['sites', 'project', projectId],
    (item: { $id: string }) => item.$id === resourceId,
    (item) => getSiteFrameworkFromModel(item),
  )
}

export function getRecentResourceSiteFramework(
  entry: Pick<RecentResource, 'kind' | 'siteFramework'>,
): string | undefined {
  if (entry.kind !== 'site') return undefined
  return entry.siteFramework
}

/** Human-readable database product or engine label for recent-resource breadcrumbs. */
export function formatRecentDatabaseTypeLabel(
  hints: RecentDatabaseIconHints,
): string | null {
  if (!hints.apiType && !hints.engine) return null
  return getDatabaseTypeDisplayLabel(hints.apiType, hints.engine)
}

export function getRecentResourceBreadcrumbs(entry: RecentResource): string[] {
  if (entry.kind !== 'database') return entry.breadcrumbs

  const typeLabel = formatRecentDatabaseTypeLabel(
    getRecentResourceDatabaseIconHints(entry),
  )
  return typeLabel ? [typeLabel] : entry.breadcrumbs
}

function findInListCache<T>(
  queryClient: QueryClient,
  queryKeyPrefix: unknown[],
  match: (item: T) => boolean,
  pick: (item: T) => string | undefined,
): string | undefined {
  const entries = queryClient.getQueriesData({ queryKey: queryKeyPrefix })
  for (const [, data] of entries) {
    if (!data || typeof data !== 'object') continue
    const list =
      (data as { buckets?: T[] }).buckets ??
      (data as { databases?: T[] }).databases ??
      (data as { users?: T[] }).users ??
      (data as { teams?: T[] }).teams ??
      (data as { functions?: T[] }).functions ??
      (data as { sites?: T[] }).sites ??
      (data as { messages?: T[] }).messages ??
      (data as { topics?: T[] }).topics ??
      (data as { providers?: T[] }).providers ??
      (Array.isArray(data) ? (data as T[]) : undefined)
    if (!list) continue
    const hit = list.find(match)
    if (hit) {
      const label = pick(hit)
      if (label) return label
    }
  }
  return undefined
}

/**
 * Parse a console pathname into a project resource reference, or null when
 * the page is not a resource detail (lists, create wizards, settings-only, etc.).
 */
export function parseRecentResourceRef(
  pathname: string,
): ParsedResourceRef | null {
  const parts = pathname.split('/').filter(Boolean)
  if (parts[0] !== 'projects' || !parts[1]) return null

  let projectId: string
  try {
    projectId = decodeURIComponent(parts[1])
  } catch {
    return null
  }

  const service = parts[2]
  if (!service) return null

  if (service === 'storage' && parts[3]) {
    const bucketId = safeDecode(parts[3])
    if (!bucketId || isStoragePlaceholderBucketId(bucketId)) return null
    if (RESERVED_SEGMENTS.has(bucketId)) return null
    return {
      projectId,
      kind: 'bucket',
      section: 'storage',
      resourceId: bucketId,
      href: `/projects/${projectId}/storage/${bucketId}`,
      breadcrumbs: ['Storage'],
    }
  }

  if (service === 'functions' && parts[3]) {
    const functionId = safeDecode(parts[3])
    if (!functionId || RESERVED_SEGMENTS.has(functionId)) return null
    return {
      projectId,
      kind: 'function',
      section: 'functions',
      resourceId: functionId,
      href: `/projects/${projectId}/functions/${functionId}`,
      breadcrumbs: ['Functions'],
    }
  }

  if (service === 'sites' && parts[3]) {
    const siteId = safeDecode(parts[3])
    if (!siteId || RESERVED_SEGMENTS.has(siteId)) return null
    return {
      projectId,
      kind: 'site',
      section: 'sites',
      resourceId: siteId,
      href: `/projects/${projectId}/sites/${siteId}`,
      breadcrumbs: ['Sites'],
    }
  }

  if (service === 'auth' && parts[3] === 'users' && parts[4]) {
    const userId = safeDecode(parts[4])
    if (!userId || RESERVED_SEGMENTS.has(userId)) return null
    return {
      projectId,
      kind: 'user',
      section: 'auth/users',
      resourceId: userId,
      href: `/projects/${projectId}/auth/users/${userId}`,
      breadcrumbs: ['Auth', 'Users'],
    }
  }

  if (service === 'auth' && parts[3] === 'teams' && parts[4]) {
    const teamId = safeDecode(parts[4])
    if (!teamId || RESERVED_SEGMENTS.has(teamId)) return null
    return {
      projectId,
      kind: 'team',
      section: 'auth/teams',
      resourceId: teamId,
      href: `/projects/${projectId}/auth/teams/${teamId}`,
      breadcrumbs: ['Auth', 'Teams'],
    }
  }

  if (service === 'messaging' && parts[3] === 'topics' && parts[4]) {
    const topicId = safeDecode(parts[4])
    if (!topicId || RESERVED_SEGMENTS.has(topicId)) return null
    return {
      projectId,
      kind: 'topic',
      section: 'messaging/topics',
      resourceId: topicId,
      href: `/projects/${projectId}/messaging/topics/${topicId}`,
      breadcrumbs: ['Messaging', 'Topics'],
    }
  }

  if (service === 'messaging' && parts[3] === 'providers' && parts[4]) {
    const providerId = safeDecode(parts[4])
    if (!providerId || RESERVED_SEGMENTS.has(providerId)) return null
    return {
      projectId,
      kind: 'provider',
      section: 'messaging/providers',
      resourceId: providerId,
      href: `/projects/${projectId}/messaging/providers/${providerId}`,
      breadcrumbs: ['Messaging', 'Providers'],
    }
  }

  if (service === 'messaging' && parts[3]) {
    const messageId = safeDecode(parts[3])
    if (
      !messageId ||
      RESERVED_SEGMENTS.has(messageId) ||
      messageId === 'topics' ||
      messageId === 'providers'
    ) {
      return null
    }
    return {
      projectId,
      kind: 'message',
      section: 'messaging/messages',
      resourceId: messageId,
      href: `/projects/${projectId}/messaging/${messageId}`,
      breadcrumbs: ['Messaging', 'Messages'],
    }
  }

  if (service === 'databases' && parts[3]) {
    const segment = safeDecode(parts[3])
    if (!segment || RESERVED_SEGMENTS.has(segment) || segment === 'create') {
      return null
    }

    // /databases/postgres/:databaseId
    if (segment === 'postgres' && parts[4]) {
      const databaseId = safeDecode(parts[4])
      if (!databaseId || RESERVED_SEGMENTS.has(databaseId)) return null
      return {
        projectId,
        kind: 'database',
        section: 'databases',
        resourceId: databaseId,
        href: `/projects/${projectId}/databases/postgres/${databaseId}`,
        breadcrumbs: ['Databases'],
      }
    }

    // /databases/:dbKind/:databaseId
    if (isDatabaseRouteKind(segment) && parts[4]) {
      const databaseId = safeDecode(parts[4])
      if (!databaseId || RESERVED_SEGMENTS.has(databaseId)) return null
      return {
        projectId,
        kind: 'database',
        section: 'databases',
        resourceId: databaseId,
        href: `/projects/${projectId}/databases/${segment}/${databaseId}`,
        breadcrumbs: ['Databases'],
      }
    }

    // Incomplete product/engine segment without a database id.
    if (segment === 'postgres' || isDatabaseRouteKind(segment)) {
      return null
    }

    // Legacy /databases/:databaseId (redirect route still counts as a visit)
    return {
      projectId,
      kind: 'database',
      section: 'databases',
      resourceId: segment,
      href: `/projects/${projectId}/databases/${segment}`,
      breadcrumbs: ['Databases'],
    }
  }

  return null
}

function safeDecode(value: string): string | null {
  try {
    return decodeURIComponent(value)
  } catch {
    return null
  }
}

/** Resolve a display name for a parsed resource from the React Query cache. */
export function resolveRecentResourceName(
  queryClient: QueryClient,
  ref: ParsedResourceRef,
): string | undefined {
  const { projectId, kind, resourceId } = ref

  switch (kind) {
    case 'bucket': {
      const direct = named(
        queryClient.getQueryData(['bucket', 'project', projectId, resourceId]),
      )
      if (direct) return direct
      return findInListCache<{ $id: string; name: string }>(
        queryClient,
        ['buckets', 'project', projectId],
        (item) => item.$id === resourceId,
        (item) => item.name,
      )
    }
    case 'function': {
      const direct = named(
        queryClient.getQueryData([
          'function',
          'project',
          projectId,
          resourceId,
        ]),
      )
      if (direct) return direct
      return findInListCache<{ $id: string; name: string }>(
        queryClient,
        ['functions', 'project', projectId],
        (item) => item.$id === resourceId,
        (item) => item.name,
      )
    }
    case 'site': {
      const direct = named(
        queryClient.getQueryData(['site', 'project', projectId, resourceId]),
      )
      if (direct) return direct
      return findInListCache<{ $id: string; name: string }>(
        queryClient,
        ['sites', 'project', projectId],
        (item) => item.$id === resourceId,
        (item) => item.name,
      )
    }
    case 'user': {
      const direct = named(
        queryClient.getQueryData(['user', 'project', projectId, resourceId]),
      )
      if (direct) return direct
      return findInListCache<{ $id: string; name?: string; email?: string }>(
        queryClient,
        ['users', 'project', projectId],
        (item) => item.$id === resourceId,
        (item) => item.name || item.email,
      )
    }
    case 'team': {
      const direct = named(
        queryClient.getQueryData(['team', 'project', projectId, resourceId]),
      )
      if (direct) return direct
      return findInListCache<{ id?: string; $id?: string; name: string }>(
        queryClient,
        ['teams', 'project', projectId],
        (item) => item.id === resourceId || item.$id === resourceId,
        (item) => item.name,
      )
    }
    case 'message': {
      const message = queryClient.getQueryData([
        'message',
        'project',
        projectId,
        resourceId,
      ]) as Models.Message | undefined
      if (message) return getMessageSearchLabel(message)
      return findInListCache<Models.Message>(
        queryClient,
        ['messages', 'project', projectId],
        (item) => item.$id === resourceId,
        (item) => getMessageSearchLabel(item),
      )
    }
    case 'topic': {
      const direct = named(
        queryClient.getQueryData(['topic', 'project', projectId, resourceId]),
      )
      if (direct) return direct
      return findInListCache<{ $id: string; name: string }>(
        queryClient,
        ['topics', 'project', projectId],
        (item) => item.$id === resourceId,
        (item) => item.name,
      )
    }
    case 'provider': {
      const direct = named(
        queryClient.getQueryData([
          'provider',
          'project',
          projectId,
          resourceId,
        ]),
      )
      if (direct) return direct
      return findInListCache<{ $id: string; name: string }>(
        queryClient,
        ['providers', 'project', projectId],
        (item) => item.$id === resourceId,
        (item) => item.name,
      )
    }
    case 'database': {
      const direct =
        named(
          queryClient.getQueryData([
            'database',
            'project',
            projectId,
            resourceId,
          ]),
        ) ??
        named(
          queryClient.getQueryData([
            'postgres-database',
            'project',
            projectId,
            resourceId,
          ]),
        )
      if (direct) return direct
      return (
        findInListCache<{ $id: string; name: string }>(
          queryClient,
          ['databases', 'project', projectId],
          (item) => item.$id === resourceId,
          (item) => item.name,
        ) ??
        findInListCache<{ $id: string; name: string }>(
          queryClient,
          ['dedicated-databases', 'project', projectId],
          (item) => item.$id === resourceId,
          (item) => item.name,
        )
      )
    }
    default:
      return undefined
  }
}

export function buildRecentResource(
  ref: ParsedResourceRef,
  name: string,
  viewedAt: number = Date.now(),
  databaseIconHints: RecentDatabaseIconHints = {},
  siteFramework?: string,
): RecentResource {
  const databaseTypeLabel =
    ref.kind === 'database'
      ? formatRecentDatabaseTypeLabel(databaseIconHints)
      : null
  const breadcrumbs =
    ref.kind === 'database' && databaseTypeLabel
      ? [databaseTypeLabel]
      : ref.breadcrumbs

  return {
    key: resourceKey(ref.projectId, ref.kind, ref.resourceId),
    kind: ref.kind,
    name,
    breadcrumbs,
    projectId: ref.projectId,
    section: ref.section,
    resourceId: ref.resourceId,
    href: ref.href,
    viewedAt,
    ...(ref.kind === 'database' && databaseIconHints.apiType
      ? { databaseApiType: databaseIconHints.apiType }
      : {}),
    ...(ref.kind === 'database' && databaseIconHints.engine
      ? { databaseEngine: databaseIconHints.engine }
      : {}),
    ...(ref.kind === 'site' && siteFramework
      ? { siteFramework }
      : {}),
  }
}

/** Insert or move a resource to the front (newest first), removing duplicates. */
export function upsertRecentResource(
  list: RecentResource[],
  entry: RecentResource,
  maxStored: number = RECENT_RESOURCES_MAX_STORED,
): RecentResource[] {
  const next = [
    entry,
    ...list.filter((item) => item.key !== entry.key),
  ].slice(0, maxStored)
  return next
}

export function readRecentResourcesFromStorage(): RecentResource[] {
  if (typeof window === 'undefined') return []
  try {
    const raw = window.localStorage.getItem(RECENT_RESOURCES_STORAGE_KEY)
    if (!raw) return []
    const parsed = JSON.parse(raw) as unknown
    if (!Array.isArray(parsed)) return []
    return parsed
      .filter(isRecentResource)
      .sort((a, b) => b.viewedAt - a.viewedAt)
      .slice(0, RECENT_RESOURCES_MAX_STORED)
  } catch {
    return []
  }
}

export function writeRecentResourcesToStorage(list: RecentResource[]): void {
  if (typeof window === 'undefined') return
  try {
    window.localStorage.setItem(
      RECENT_RESOURCES_STORAGE_KEY,
      JSON.stringify(list.slice(0, RECENT_RESOURCES_MAX_STORED)),
    )
  } catch {
    // Ignore quota / private mode errors.
  }
}

function isRecentResource(value: unknown): value is RecentResource {
  if (!value || typeof value !== 'object') return false
  const item = value as RecentResource
  return (
    typeof item.key === 'string' &&
    typeof item.kind === 'string' &&
    typeof item.name === 'string' &&
    Array.isArray(item.breadcrumbs) &&
    typeof item.projectId === 'string' &&
    typeof item.section === 'string' &&
    typeof item.resourceId === 'string' &&
    typeof item.href === 'string' &&
    typeof item.viewedAt === 'number'
  )
}

export function filterRecentResources(
  list: RecentResource[],
  options: {
    projectId?: string | null
    limit?: number
    /** Omit the newest entry when it matches the page the user is already on. */
    skipNewestWhenMatches?: Pick<
      RecentResource,
      'projectId' | 'kind' | 'resourceId'
    > | null
  } = {},
): RecentResource[] {
  const {
    projectId,
    limit = RECENT_RESOURCES_MAX_SHOWN,
    skipNewestWhenMatches,
  } = options
  const filtered = projectId
    ? list.filter((item) => item.projectId === projectId)
    : list

  let start = 0
  const newest = filtered[0]
  if (
    skipNewestWhenMatches &&
    newest &&
    newest.projectId === skipNewestWhenMatches.projectId &&
    newest.kind === skipNewestWhenMatches.kind &&
    newest.resourceId === skipNewestWhenMatches.resourceId
  ) {
    start = 1
  }

  return filtered.slice(start, start + limit)
}
