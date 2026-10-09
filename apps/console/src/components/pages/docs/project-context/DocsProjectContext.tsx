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
import { useAuth } from '@/components/global/auth/RequireAuth'
import { getProjectListItemEndpoint } from '@/lib/react-query/hooks/projects'

const STORAGE_KEY = 'docs:project'

export type DocsProjectSelection = {
  id: string
  name: string
  region: string
  orgId: string
}

export type DocsActiveProject = DocsProjectSelection & { endpoint: string }

type DocsProjectContextValue = {
  /** False outside the docs shell, where the picker and code filling are off. */
  available: boolean
  /** The chosen project, only while signed in and after hydration. */
  project: DocsActiveProject | null
  setProject: (project: DocsProjectSelection | null) => void
  isAuthenticated: boolean
  authLoading: boolean
}

const FALLBACK_CONTEXT: DocsProjectContextValue = {
  available: false,
  project: null,
  setProject: () => {},
  isAuthenticated: false,
  authLoading: false,
}

const DocsProjectContext =
  createContext<DocsProjectContextValue>(FALLBACK_CONTEXT)

function readStoredProject(): DocsProjectSelection | null {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as Partial<DocsProjectSelection>
    if (
      typeof parsed.id !== 'string' ||
      typeof parsed.name !== 'string' ||
      typeof parsed.orgId !== 'string'
    ) {
      return null
    }
    return {
      id: parsed.id,
      name: parsed.name,
      orgId: parsed.orgId,
      region: typeof parsed.region === 'string' ? parsed.region : 'unknown',
    }
  } catch {
    return null
  }
}

function writeStoredProject(project: DocsProjectSelection | null) {
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

export function DocsProjectProvider({ children }: { children: ReactNode }) {
  const { isAuthenticated, isLoading } = useAuth()
  const [stored, setStored] = useState<DocsProjectSelection | null>(null)
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

  const setProject = useCallback((project: DocsProjectSelection | null) => {
    setStored(project)
    writeStoredProject(project)
  }, [])

  const project = useMemo<DocsActiveProject | null>(() => {
    if (!hydrated || !isAuthenticated || !stored) return null
    return { ...stored, endpoint: getProjectListItemEndpoint(stored) }
  }, [hydrated, isAuthenticated, stored])

  const value = useMemo<DocsProjectContextValue>(
    () => ({
      available: true,
      project,
      setProject,
      isAuthenticated: hydrated && isAuthenticated,
      authLoading: !hydrated || isLoading,
    }),
    [project, setProject, hydrated, isAuthenticated, isLoading],
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
