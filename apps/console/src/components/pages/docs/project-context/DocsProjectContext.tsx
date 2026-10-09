'use client'

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import type { Models } from '@appwrite.io/console'
import { useQuery } from '@tanstack/react-query'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  getProjectListItemEndpoint,
  projectQueryOptions,
} from '@/lib/react-query/hooks/projects'

const STORAGE_KEY = 'docs:project'

export type DocsProjectSelection = {
  id: string
  name: string
  region: string
  orgId: string
}

/** Saved with the account that picked it, so another sign-in never inherits it. */
type StoredDocsProject = DocsProjectSelection & { accountId: string }

export type DocsActiveProject = DocsProjectSelection & { endpoint: string }

type DocsProjectContextValue = {
  /** False outside the docs shell, where the picker is off. */
  available: boolean
  /** The chosen project, only for the account that chose it and after hydration. */
  project: DocsActiveProject | null
  setProject: (project: DocsProjectSelection | null) => void
  isAuthenticated: boolean
}

const FALLBACK_CONTEXT: DocsProjectContextValue = {
  available: false,
  project: null,
  setProject: () => {},
  isAuthenticated: false,
}

const DocsProjectContext =
  createContext<DocsProjectContextValue>(FALLBACK_CONTEXT)

function readStoredProject(): StoredDocsProject | null {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as Partial<StoredDocsProject>
    if (
      typeof parsed.id !== 'string' ||
      typeof parsed.name !== 'string' ||
      typeof parsed.orgId !== 'string' ||
      typeof parsed.accountId !== 'string'
    ) {
      return null
    }
    return {
      id: parsed.id,
      name: parsed.name,
      orgId: parsed.orgId,
      accountId: parsed.accountId,
      region: typeof parsed.region === 'string' ? parsed.region : 'unknown',
    }
  } catch {
    return null
  }
}

function writeStoredProject(project: StoredDocsProject | null) {
  try {
    if (project) {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(project))
    } else {
      window.localStorage.removeItem(STORAGE_KEY)
    }
  } catch {
    // Storage can be unavailable (private mode). The choice then lasts for this page only.
  }
}

function isProjectUnavailableError(error: unknown): boolean {
  if (!error || typeof error !== 'object') return false
  const code = (error as { code?: unknown }).code
  return code === 401 || code === 403 || code === 404
}

export function DocsProjectProvider({ children }: { children: ReactNode }) {
  const { account: accountUnknown, isAuthenticated } = useAuth()
  const account = accountUnknown as Models.User | undefined
  const accountId = isAuthenticated ? (account?.$id ?? null) : null
  const [stored, setStored] = useState<StoredDocsProject | null>(null)
  const [hydrated, setHydrated] = useState(false)

  useEffect(() => {
    setStored(readStoredProject())
    setHydrated(true)

    const onStorage = (event: StorageEvent) => {
      if (event.key === STORAGE_KEY) setStored(readStoredProject())
    }
    window.addEventListener('storage', onStorage)
    return () => window.removeEventListener('storage', onStorage)
  }, [])

  const setProject = useCallback(
    (project: DocsProjectSelection | null) => {
      const next = project && accountId ? { ...project, accountId } : null
      setStored(next)
      writeStoredProject(next)
    },
    [accountId],
  )

  // Only the account that picked the project gets it back.
  const candidate =
    hydrated && stored && accountId && stored.accountId === accountId
      ? stored
      : null

  // Drop a project the account can no longer open (deleted, or access removed).
  // Other failures, such as a network error, keep the choice. The shared
  // project query never refetches on its own, so check access again on mount
  // and focus instead of trusting a cached response.
  const { error: projectError } = useQuery({
    ...projectQueryOptions(candidate?.id),
    staleTime: 0,
    refetchOnMount: 'always',
    refetchOnWindowFocus: true,
  })
  const projectUnavailable = isProjectUnavailableError(projectError)
  useEffect(() => {
    if (projectUnavailable) setProject(null)
  }, [projectUnavailable, setProject])

  const project = useMemo<DocsActiveProject | null>(() => {
    if (!candidate || projectUnavailable) return null
    const { id, name, region, orgId } = candidate
    return {
      id,
      name,
      region,
      orgId,
      endpoint: getProjectListItemEndpoint({ region }),
    }
  }, [candidate, projectUnavailable])

  const value = useMemo<DocsProjectContextValue>(
    () => ({
      available: true,
      project,
      setProject,
      isAuthenticated: hydrated && !!accountId,
    }),
    [project, setProject, hydrated, accountId],
  )

  return (
    <DocsProjectContext.Provider value={value}>
      {children}
    </DocsProjectContext.Provider>
  )
}

export function useDocsProject(): DocsProjectContextValue {
  return useContext(DocsProjectContext)
}
