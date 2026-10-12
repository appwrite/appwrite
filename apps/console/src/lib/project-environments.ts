import { useCallback, useEffect, useState } from 'react'

export type ProjectEnvironmentType =
  | 'production'
  | 'staging'
  | 'development'
  | 'preview'

export interface ProjectEnvironment {
  id: string
  name: string
  type: ProjectEnvironmentType
}

/** Mock environments until the API ships; every project gets the same set. */
export const MOCK_PROJECT_ENVIRONMENTS: ProjectEnvironment[] = [
  { id: 'production', name: 'Production', type: 'production' },
  { id: 'staging', name: 'Staging', type: 'staging' },
  { id: 'development', name: 'Development', type: 'development' },
  { id: 'preview', name: 'Preview', type: 'preview' },
]

export const DEFAULT_PROJECT_ENVIRONMENT_ID = 'production'

export const PROJECT_ENVIRONMENT_DOT_CLASS: Record<
  ProjectEnvironmentType,
  string
> = {
  production: 'bg-emerald-500',
  staging: 'bg-amber-500',
  development: 'bg-sky-500',
  preview: 'bg-violet-500',
}

const STORAGE_EVENT = 'projectEnvironmentChange'

function storageKey(projectId: string) {
  return `console.projectEnvironment.${projectId}`
}

function readEnvironmentId(projectId: string): string {
  if (typeof window === 'undefined') return DEFAULT_PROJECT_ENVIRONMENT_ID
  const stored = window.localStorage.getItem(storageKey(projectId))
  return MOCK_PROJECT_ENVIRONMENTS.some((env) => env.id === stored)
    ? (stored as string)
    : DEFAULT_PROJECT_ENVIRONMENT_ID
}

/**
 * Selected environment for a project, persisted per device. Starts on the
 * default so SSR and first client render match; stored choice applies after mount.
 */
export function useProjectEnvironment(projectId: string) {
  const [environmentId, setEnvironmentIdState] = useState(
    DEFAULT_PROJECT_ENVIRONMENT_ID,
  )

  useEffect(() => {
    setEnvironmentIdState(readEnvironmentId(projectId))
    const sync = () => setEnvironmentIdState(readEnvironmentId(projectId))
    window.addEventListener(STORAGE_EVENT, sync)
    window.addEventListener('storage', sync)
    return () => {
      window.removeEventListener(STORAGE_EVENT, sync)
      window.removeEventListener('storage', sync)
    }
  }, [projectId])

  const setEnvironmentId = useCallback(
    (id: string) => {
      window.localStorage.setItem(storageKey(projectId), id)
      window.dispatchEvent(new CustomEvent(STORAGE_EVENT))
    },
    [projectId],
  )

  const environment =
    MOCK_PROJECT_ENVIRONMENTS.find((env) => env.id === environmentId) ??
    MOCK_PROJECT_ENVIRONMENTS[0]

  return {
    environment,
    environments: MOCK_PROJECT_ENVIRONMENTS,
    setEnvironmentId,
  }
}
