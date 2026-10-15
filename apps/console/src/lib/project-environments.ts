import { useCallback, useEffect, useState } from 'react'

/**
 * A row in the environment switcher: either the project itself (the default
 * environment, which has no environment document of its own) or one of the
 * environments listed under it.
 */
export interface ProjectEnvironmentOption {
  id: string
  name: string
  /** The project itself. It cannot be renamed or deleted from the switcher. */
  isDefault: boolean
}

/**
 * The switcher's rows. The project itself is always the first one, so there is
 * always something selected.
 */
export type ProjectEnvironmentOptions = readonly [
  ProjectEnvironmentOption,
  ...ProjectEnvironmentOption[],
]

/**
 * Dot colors for the switcher. The project itself always takes the first one;
 * every other environment keeps the same color between renders because the
 * color is derived from its id (an environment has no type to key on).
 */
const PROJECT_ENVIRONMENT_DOT_CLASSES = [
  'bg-emerald-500',
  'bg-amber-500',
  'bg-sky-500',
  'bg-violet-500',
  'bg-rose-500',
  'bg-cyan-500',
] as const

function hashEnvironmentId(environmentId: string): number {
  let hash = 0
  for (let index = 0; index < environmentId.length; index += 1) {
    hash = (hash << 5) - hash + environmentId.charCodeAt(index)
    hash |= 0
  }
  return Math.abs(hash)
}

/** Tailwind background class for an environment's dot. */
export function projectEnvironmentDotClass(
  environment: ProjectEnvironmentOption,
): string {
  if (environment.isDefault) return PROJECT_ENVIRONMENT_DOT_CLASSES[0]
  const palette = PROJECT_ENVIRONMENT_DOT_CLASSES.slice(1)
  return palette[hashEnvironmentId(environment.id) % palette.length]!
}

const STORAGE_EVENT = 'projectEnvironmentChange'

/** Per-device selection key. Exported so tests can read what the UI wrote. */
export function projectEnvironmentStorageKey(projectId: string): string {
  return `console.projectEnvironment.${projectId}`
}

/** Stored environment id, or `null` for the project itself (and during SSR). */
export function readStoredProjectEnvironmentId(
  projectId: string,
): string | null {
  if (typeof window === 'undefined') return null
  return window.localStorage.getItem(projectEnvironmentStorageKey(projectId))
}

/** Persist the selection. `null` clears it, which means the project itself. */
export function writeStoredProjectEnvironmentId(
  projectId: string,
  environmentId: string | null,
): void {
  if (typeof window === 'undefined') return
  const key = projectEnvironmentStorageKey(projectId)
  if (environmentId) {
    window.localStorage.setItem(key, environmentId)
  } else {
    window.localStorage.removeItem(key)
  }
}

/**
 * Resolve a stored selection against the rows the switcher lists. The project
 * itself is `options[0]`, which is the fallback for an id no row matches.
 * `isStale` marks exactly that case: the stored environment is gone (deleted
 * here or on another device) and the id should be dropped.
 */
export function resolveProjectEnvironmentSelection(
  options: ProjectEnvironmentOptions,
  selectedEnvironmentId: string | null,
): { selected: ProjectEnvironmentOption; isStale: boolean } {
  const match = selectedEnvironmentId
    ? options.find((option) => option.id === selectedEnvironmentId)
    : undefined
  return {
    selected: match ?? options[0],
    isStale: !!selectedEnvironmentId && !match,
  }
}

/**
 * Selected environment for a project, persisted per device and keyed on the
 * real environment id. `null` means the project itself, which is also what SSR
 * and the first client render use so the stored choice only applies after
 * mount. Callers resolve the id against the listed environments and fall back
 * to the project when the stored environment is gone (deleted elsewhere).
 */
export function useProjectEnvironmentSelection(projectId: string) {
  const [selectedEnvironmentId, setSelectedEnvironmentId] = useState<
    string | null
  >(null)

  useEffect(() => {
    const sync = () =>
      setSelectedEnvironmentId(readStoredProjectEnvironmentId(projectId))
    sync()
    window.addEventListener(STORAGE_EVENT, sync)
    window.addEventListener('storage', sync)
    return () => {
      window.removeEventListener(STORAGE_EVENT, sync)
      window.removeEventListener('storage', sync)
    }
  }, [projectId])

  const selectEnvironment = useCallback(
    (environmentId: string | null) => {
      writeStoredProjectEnvironmentId(projectId, environmentId)
      window.dispatchEvent(new CustomEvent(STORAGE_EVENT))
    },
    [projectId],
  )

  return { selectedEnvironmentId, selectEnvironment }
}
