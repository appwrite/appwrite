/**
 * Shared React Query merge helpers for migration realtime payloads.
 */

import type { QueryClient } from '@tanstack/react-query'

/** Realtime payload may have statusCounters as JSON string; normalize to object */
export function normalizeMigrationPayload(
  payload: Record<string, unknown>,
): Record<string, unknown> {
  const out = { ...payload }
  if (typeof out.statusCounters === 'string') {
    try {
      out.statusCounters = JSON.parse(out.statusCounters as string) as object
    } catch {
      // leave as-is
    }
  }
  if (typeof out.resourceData === 'string') {
    try {
      out.resourceData = JSON.parse(out.resourceData as string) as object
    } catch {
      // leave as-is
    }
  }
  return out
}

/**
 * Merge a migration realtime payload into all migration list caches for this project.
 */
export function mergeMigrationPayloadIntoCache(
  queryClient: QueryClient,
  projectId: string,
  payload: Record<string, unknown>,
): void {
  const id = payload.$id as string | undefined
  if (!id) return

  const normalized = normalizeMigrationPayload(payload)

  queryClient.setQueriesData(
    { queryKey: ['migrations', 'project', projectId], exact: false },
    (old: unknown) => {
      const data = old as
        | { migrations?: Array<Record<string, unknown>> }
        | undefined
      if (!data?.migrations || !Array.isArray(data.migrations)) return old
      const next = data.migrations.map((m) =>
        m.$id === id ? { ...m, ...normalized } : m,
      )
      return { ...data, migrations: next }
    },
  )

  queryClient.setQueriesData(
    { queryKey: ['migration', 'project', projectId], exact: false },
    (old: unknown) => {
      if (!old || typeof old !== 'object') return old
      const migration = old as Record<string, unknown>
      if (migration.$id !== id) return old
      return { ...migration, ...normalized }
    },
  )
}

/** Map restoration lifecycle statuses to migration statuses when syncing caches. */
export function migrationStatusFromRestorationStatus(
  status: unknown,
): string | undefined {
  if (typeof status !== 'string') return undefined
  const normalized = status.trim().toLowerCase()
  if (normalized === 'running') return 'processing'
  if (
    normalized === 'pending' ||
    normalized === 'processing' ||
    normalized === 'uploading' ||
    normalized === 'downloading' ||
    normalized === 'completed' ||
    normalized === 'failed'
  ) {
    return normalized
  }
  return undefined
}
