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
  resolveRecentResourceName,
  upsertRecentResource,
  writeRecentResourcesToStorage,
  type RecentResource,
} from '@/lib/command-center/recent-resources'

interface RecentResourcesContextType {
  resources: RecentResource[]
  getRecentResources: (options?: {
    projectId?: string | null
    limit?: number
  }) => RecentResource[]
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
        existing.href === entry.href
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

    const tryRecord = () => {
      if (done) return
      const name = resolveRecentResourceName(queryClient, ref)
      if (!name) return
      recordResource(buildRecentResource(ref, name))
      finish()
    }

    tryRecord()
    if (done) return

    // Detail loaders often populate the cache just after navigation.
    for (const ms of [50, 200, 500, 1500]) {
      timeouts.push(window.setTimeout(tryRecord, ms))
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

  const value = useMemo(
    () => ({ resources, getRecentResources }),
    [resources, getRecentResources],
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
