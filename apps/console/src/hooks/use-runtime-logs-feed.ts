import { useEffect, useMemo, useRef, useState } from 'react'
import { keepPreviousData, useInfiniteQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import {
  canListRuntimeLogs,
  fetchRuntimeLogsSince,
  RUNTIME_LOGS_PAGE_LIMIT,
  runtimeLogsInfiniteQueryOptions,
  type RuntimeLogScope,
  type RuntimeLogsFilter,
} from '@/lib/react-query/hooks/runtime-logs'
import {
  toRuntimeLogEntry,
  type RuntimeLogEntry,
} from '@/lib/runtime-log-entry'
import { getErrorMessage } from '@/lib/utils/error-formatting'

/** Live tail polls this often. The logs store has a small pool, so keep it modest. */
export const RUNTIME_LOGS_TAIL_INTERVAL_MS = 5000

/** Live tail keeps at most this many new lines above the fetched pages. */
const TAIL_MAX_LINES = 1000

/** Full pages one live tail poll may read before waiting for the next poll. */
const TAIL_MAX_PAGES_PER_TICK = 5

/**
 * The lines a runtime logs view shows, newest first: cursor pages read on
 * demand, plus, while live, the lines written since, polled in order. Every
 * row is parsed once into an entry.
 */
export function useRuntimeLogsFeed({
  projectId,
  resourceType,
  resourceId,
  filter,
  live,
}: {
  projectId: string | null | undefined
  resourceType: RuntimeLogScope
  resourceId: string | null | undefined
  filter: RuntimeLogsFilter
  live: boolean
}) {
  const ready = canListRuntimeLogs(projectId, resourceType, resourceId, filter)
  const query = useInfiniteQuery({
    ...runtimeLogsInfiniteQueryOptions(
      projectId,
      resourceType,
      resourceId,
      filter,
    ),
    placeholderData: keepPreviousData,
  })
  const [tail, setTail] = useState<Models.RuntimeLog[]>([])
  const [tailError, setTailError] = useState<string | null>(null)

  const fetched = useMemo(
    () => query.data?.pages.flatMap((page) => page.logs) ?? [],
    [query.data],
  )

  // New filters or a refetch start the tail over from the fetched pages
  const firstPage = query.data?.pages[0]
  useEffect(() => {
    setTail([])
    setTailError(null)
  }, [filter, firstPage])

  const logs = useMemo(() => {
    if (tail.length === 0) return fetched
    const seen = new Set(tail.map((log) => log.$id))
    return [...tail, ...fetched.filter((log) => !seen.has(log.$id))]
  }, [tail, fetched])

  // Read by the tail loop without restarting it on every new line
  const newestRef = useRef<{ timestamp: string; ids: Set<string> } | null>(null)
  useEffect(() => {
    const newest = logs[0]
    if (!newest) {
      newestRef.current = null
      return
    }
    const ids = new Set(
      logs
        .filter((log) => log.timestamp === newest.timestamp)
        .map((log) => log.$id),
    )
    newestRef.current = { timestamp: newest.timestamp, ids }
  }, [logs])

  useEffect(() => {
    if (!live || !ready || !projectId) return
    const startedAt = new Date().toISOString()
    let cancelled = false
    let inFlight = false

    const tick = async () => {
      if (inFlight || document.visibilityState === 'hidden') return
      inFlight = true
      try {
        let cursor = newestRef.current ?? {
          timestamp: startedAt,
          ids: new Set<string>(),
        }
        // Keep reading full pages so a busy deployment doesn't fall behind
        for (let page = 0; page < TAIL_MAX_PAGES_PER_TICK; page++) {
          const rows = await fetchRuntimeLogsSince(
            projectId,
            resourceType,
            resourceId,
            filter,
            cursor.timestamp,
          )
          if (cancelled) return
          const seen = cursor.ids
          const fresh = rows.filter((row) => !seen.has(row.$id))
          if (fresh.length === 0) break
          setTail((current) =>
            [...[...fresh].reverse(), ...current].slice(0, TAIL_MAX_LINES),
          )
          const last = fresh[fresh.length - 1]
          const ids = new Set(
            fresh
              .filter((row) => row.timestamp === last.timestamp)
              .map((row) => row.$id),
          )
          if (last.timestamp === cursor.timestamp) {
            for (const id of seen) ids.add(id)
          }
          cursor = { timestamp: last.timestamp, ids }
          if (rows.length < RUNTIME_LOGS_PAGE_LIMIT) break
        }
        setTailError(null)
      } catch (e) {
        if (!cancelled) setTailError(getErrorMessage(e))
      } finally {
        inFlight = false
      }
    }

    void tick()
    const interval = setInterval(tick, RUNTIME_LOGS_TAIL_INTERVAL_MS)
    return () => {
      cancelled = true
      clearInterval(interval)
    }
  }, [live, ready, projectId, resourceType, resourceId, filter])

  // Parsed once per row, not on every render or keystroke
  const cache = useRef(new Map<string, RuntimeLogEntry>())
  const entries = useMemo(() => {
    const previous = cache.current
    const next = new Map<string, RuntimeLogEntry>()
    const rows = logs.map((log) => {
      const hit = previous.get(log.$id)
      const entry = hit && hit.log === log ? hit : toRuntimeLogEntry(log)
      next.set(log.$id, entry)
      return entry
    })
    cache.current = next
    return rows
  }, [logs])

  // Lines that arrived while live, so the list can mark them as they land
  const arrived = useMemo(() => new Set(tail.map((log) => log.$id)), [tail])

  return {
    ready,
    entries,
    arrived,
    /** Cursor pages read so far, not counting live lines. */
    pages: query.data?.pages.length ?? 0,
    tailError,
    error: query.error,
    isLoading: query.isLoading,
    isFetching: query.isFetching,
    isFetchingNextPage: query.isFetchingNextPage,
    hasNextPage: query.hasNextPage,
    fetchNextPage: query.fetchNextPage,
    refetch: query.refetch,
  }
}
