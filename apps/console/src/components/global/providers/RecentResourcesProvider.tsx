import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { useLocation } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import {
  buildRecentResource,
  filterRecentResources,
  parseRecentResourceRef,
  readRecentResourcesFromStorage,
  resolveRecentDatabaseIconHints,
  resolveRecentResourceName,
  resolveRecentSiteFramework,
  upsertRecentResource,
  writeRecentResourcesToStorage,
  type RecentResource,
} from '@/lib/command-center/recent-resources'

interface RecentResourcesContextType {
  resources: RecentResource[]
  getRecentResources: (options?: {
    projectId?: string | null
    limit?: number
    skipNewestWhenMatches?: Pick<
      RecentResource,
      'projectId' | 'kind' | 'resourceId'
    > | null
  }) => RecentResource[]
  clearRecentResources: () => void
}

const RecentResourcesContext =
  createContext<RecentResourcesContextType | null>(null)

interface RecentResourcesProviderProps {
  children: ReactNode
}

/**
 * Tracks recently viewed project resources for the Command Center.
 * Newest first, deduplicated by resource identity (not page title / tab).
 */
export function RecentResourcesProvider({
  children,
}: RecentResourcesProviderProps) {
  const location = useLocation()
  const queryClient = useQueryClient()
  const [resources, setResources] = useState<RecentResource[]>(() =>
    readRecentResourcesFromStorage(),
  )

  const recordResource = useCallback((entry: RecentResource) => {
    setResources((current) => {
      const existing = current.find((item) => item.key === entry.key)
      // Same resource already newest with the same name: skip write.
      if (
        existing &&
        current[0]?.key === entry.key &&
        existing.name === entry.name &&
        existing.href === entry.href &&
        existing.databaseApiType === entry.databaseApiType &&
        existing.databaseEngine === entry.databaseEngine &&
        existing.siteFramework === entry.siteFramework
      ) {
        return current
      }
      const next = upsertRecentResource(current, entry)
      writeRecentResourcesToStorage(next)
      return next
    })
  }, [])

  useEffect(() => {
    const ref = parseRecentResourceRef(location.pathname)
    if (!ref) return

    let done = false
    let unsubscribe: (() => void) | undefined
    const timeouts: number[] = []

    const finish = () => {
      if (done) return
      done = true
      timeouts.forEach((id) => window.clearTimeout(id))
      unsubscribe?.()
      unsubscribe = undefined
    }

    const tryRecord = (options?: { forceFinish?: boolean }) => {
      if (done) return
      const name = resolveRecentResourceName(queryClient, ref)
      if (!name) {
        if (options?.forceFinish) finish()
        return
      }
      const databaseIconHints = resolveRecentDatabaseIconHints(queryClient, ref)
      const siteFramework = resolveRecentSiteFramework(queryClient, ref)
      recordResource(
        buildRecentResource(
          ref,
          name,
          Date.now(),
          databaseIconHints,
          siteFramework,
        ),
      )
      // Sites need the framework for the correct icon; keep waiting until it
      // lands in cache (or the final retry forces finish).
      if (ref.kind === 'site' && !siteFramework && !options?.forceFinish) {
        return
      }
      finish()
    }

    tryRecord()
    if (done) return

    // Detail loaders often populate the cache just after navigation.
    const retryDelays = [50, 200, 500, 1500]
    for (const ms of retryDelays) {
      timeouts.push(
        window.setTimeout(
          () =>
            tryRecord({
              forceFinish: ms === retryDelays[retryDelays.length - 1],
            }),
          ms,
        ),
      )
    }

    unsubscribe = queryClient.getQueryCache().subscribe(() => {
      tryRecord()
    })

    return () => {
      finish()
    }
  }, [location.pathname, queryClient, recordResource])

  const getRecentResources = useCallback(
    (options?: { projectId?: string | null; limit?: number }) =>
      filterRecentResources(resources, options),
    [resources],
  )

  const clearRecentResources = useCallback(() => {
    setResources([])
    writeRecentResourcesToStorage([])
  }, [])

  const value = useMemo(
    () => ({ resources, getRecentResources, clearRecentResources }),
    [resources, getRecentResources, clearRecentResources],
  )

  return (
    <RecentResourcesContext.Provider value={value}>
      {children}
    </RecentResourcesContext.Provider>
  )
}

export function useRecentResources(): RecentResourcesContextType {
  const context = useContext(RecentResourcesContext)
  if (!context) {
    throw new Error(
      'useRecentResources must be used within a RecentResourcesProvider',
    )
  }
  return context
}

export function useRecentResourcesSafe(): RecentResourcesContextType | null {
  return useContext(RecentResourcesContext)
}
