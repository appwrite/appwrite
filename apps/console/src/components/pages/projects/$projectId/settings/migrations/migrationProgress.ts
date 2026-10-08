/**
 * Shared helpers for migration progress from statusCounters.
 * Used by the migrations table (progress column) and details drawer.
 */

import type { Models } from '@appwrite.io/console'

export interface StatusCounter {
  pending?: number
  success?: number
  error?: number
  skip?: number
  processing?: number
  warning?: number
}

export function parseStatusCounters(
  m: Models.Migration,
): Record<string, StatusCounter> {
  if (!m.statusCounters) return {}
  try {
    const raw =
      typeof m.statusCounters === 'string'
        ? JSON.parse(m.statusCounters as string)
        : m.statusCounters
    return typeof raw === 'object' && raw !== null ? raw : {}
  } catch {
    return {}
  }
}

/** Succeeded and total item counts from statusCounters for "X / Y" display. */
export function getMigrationCounts(m: Models.Migration): {
  succeeded: number
  total: number
} {
  const map = parseStatusCounters(m)
  let succeeded = 0
  let total = 0
  for (const counter of Object.values(map)) {
    const c = (counter || {}) as StatusCounter
    const pending = c.pending ?? 0
    const success = c.success ?? 0
    const error = c.error ?? 0
    const skip = c.skip ?? 0
    const processing = c.processing ?? 0
    const warning = c.warning ?? 0
    succeeded += success
    total +=
      success + error + skip + warning + Math.max(0, pending) + processing
  }
  return { succeeded, total }
}

/**
 * Progress % from statusCounters.
 *
 * When `resourceKeys` is provided (e.g. restoration.resources), averages
 * per-resource-type progress so finishing the first type (database 1/1)
 * does not jump the bar to 100% while tables/rows are still pending.
 * Resource types not yet in statusCounters count as 0%. Empty types (0/0)
 * are skipped. Completed/failed migrations are always 100%.
 */
export function getMigrationProgress(
  m: Models.Migration,
  resourceKeys?: string[],
): number {
  if (m.status === 'failed' || m.status === 'completed') return 100
  const map = parseStatusCounters(m)

  if (resourceKeys && resourceKeys.length > 0) {
    let sum = 0
    let counted = 0
    for (const key of sortRestoreResourceKeys(resourceKeys)) {
      if (!key) continue
      const c = map[key] as StatusCounter | undefined
      if (!c) {
        sum += 0
        counted += 1
        continue
      }
      const pending = c.pending ?? 0
      const success = c.success ?? 0
      const error = c.error ?? 0
      const skip = c.skip ?? 0
      const processing = c.processing ?? 0
      const warning = c.warning ?? 0
      const total = pending + success + error + skip + processing + warning
      if (total === 0) continue
      const done = success + error + skip + warning
      sum += (done / total) * 100
      counted += 1
    }
    if (counted === 0) return 0
    return Math.round(Math.min(100, Math.max(0, sum / counted)))
  }

  let totalDone = 0
  let totalInProgress = 0
  for (const counter of Object.values(map)) {
    const c = (counter || {}) as StatusCounter
    const pending = c.pending ?? 0
    const success = c.success ?? 0
    const error = c.error ?? 0
    const skip = c.skip ?? 0
    const processing = c.processing ?? 0
    const warning = c.warning ?? 0
    totalDone += success + error + skip + warning
    totalInProgress += pending + processing
  }
  const total = totalDone + totalInProgress
  if (total === 0) return 0
  const pct = (totalDone / total) * 100
  return Math.round(Math.min(100, Math.max(0, pct)))
}

export type MigrationResourceBreakdownItem = {
  key: string
  label: string
  succeeded: number
  total: number
  error: number
  processing: number
  pending: number
  /** waiting | processing | success | error */
  tone: 'waiting' | 'processing' | 'success' | 'error'
}

function resourceTypeLabel(key: string, total: number): string {
  const singular = key.charAt(0).toUpperCase() + key.slice(1).toLowerCase()
  if (total === 1) return singular
  // Avoid awkward "Indexs" / "Database" plurals for common restore types
  const plurals: Record<string, string> = {
    database: 'Databases',
    table: 'Tables',
    column: 'Columns',
    row: 'Rows',
    index: 'Indexes',
    collection: 'Collections',
    document: 'Documents',
    attribute: 'Attributes',
    vectorsdb: 'VectorsDB',
    documentsdb: 'DocumentsDB',
  }
  return plurals[key.toLowerCase()] ?? `${singular}s`
}

/**
 * Canonical restore execution order (columns/attributes before indexes).
 * API `resources` often lists index before column; UI must not follow that.
 */
const RESTORE_RESOURCE_SEQUENCE = [
  'database',
  'databases',
  'documentsdb',
  'vectorsdb',
  'table',
  'tables',
  'collection',
  'collections',
  'column',
  'columns',
  'attribute',
  'attributes',
  'index',
  'indexes',
  'row',
  'rows',
  'document',
  'documents',
] as const

function normalizeRestoreResourceKey(key: string): string {
  return String(key).trim().toLowerCase()
}

/** Sort resource keys into restore execution order. */
export function sortRestoreResourceKeys(keys: string[]): string[] {
  return keys
    .map((key, index) => ({ key, index }))
    .sort((a, b) => {
      const aNorm = normalizeRestoreResourceKey(a.key)
      const bNorm = normalizeRestoreResourceKey(b.key)
      const aRank = RESTORE_RESOURCE_SEQUENCE.indexOf(
        aNorm as (typeof RESTORE_RESOURCE_SEQUENCE)[number],
      )
      const bRank = RESTORE_RESOURCE_SEQUENCE.indexOf(
        bNorm as (typeof RESTORE_RESOURCE_SEQUENCE)[number],
      )
      const aKnown = aRank >= 0
      const bKnown = bRank >= 0
      if (aKnown && bKnown && aRank !== bRank) return aRank - bRank
      if (aKnown && !bKnown) return -1
      if (!aKnown && bKnown) return 1
      return a.index - b.index
    })
    .map(({ key }) => key)
}

/**
 * Per-resource-type progress from statusCounters.
 * `resourceKeys` keeps a stable set (e.g. restoration.resources); display order
 * follows restore execution (columns/attributes before indexes).
 */
export function getMigrationResourceBreakdown(
  migration: Models.Migration | null | undefined,
  resourceKeys: string[] = [],
): MigrationResourceBreakdownItem[] {
  const map = migration ? parseStatusCounters(migration) : {}
  const keys = [
    ...resourceKeys,
    ...Object.keys(map).filter((key) => !resourceKeys.includes(key)),
  ]
  // De-dupe while preserving order, then apply canonical restore order
  const seen = new Set<string>()
  const ordered = sortRestoreResourceKeys(
    keys.filter((key) => {
      if (!key || seen.has(key)) return false
      seen.add(key)
      return true
    }),
  )

  return ordered.map((key) => {
    const c = (map[key] || {}) as StatusCounter
    const pending = c.pending ?? 0
    const success = c.success ?? 0
    const error = c.error ?? 0
    const skip = c.skip ?? 0
    const processing = c.processing ?? 0
    const warning = c.warning ?? 0
    const total = pending + success + error + skip + processing + warning
    const succeeded = success
    let tone: MigrationResourceBreakdownItem['tone'] = 'waiting'
    if (error > 0) tone = 'error'
    else if (pending > 0 || processing > 0) tone = 'processing'
    else if (total > 0 && success + skip + warning === total) tone = 'success'

    return {
      key,
      label: resourceTypeLabel(key, total > 1 ? total : 2),
      succeeded,
      total,
      error,
      processing,
      pending,
      tone,
    }
  })
}

/**
 * Migration errors are often JSON strings with { message, resourceName, ... }.
 * Return a short user-facing message (never raw JSON).
 */
export function getMigrationErrorMessage(
  errors: unknown[] | undefined | null,
): string | null {
  if (!errors?.length) return null
  const error = errors[0]
  try {
    const parsed =
      typeof error === 'string'
        ? (JSON.parse(error) as Record<string, unknown>)
        : (error as Record<string, unknown>)
    if (parsed && typeof parsed === 'object') {
      if (typeof parsed.message === 'string' && parsed.message.trim()) {
        return parsed.message.trim()
      }
      if (typeof parsed.error === 'string' && parsed.error.trim()) {
        return parsed.error.trim()
      }
    }
  } catch {
    // fall through
  }
  if (typeof error === 'string' && error.trim()) {
    // Avoid dumping JSON blobs into the UI
    if (error.trimStart().startsWith('{')) return null
    return error
  }
  return null
}
