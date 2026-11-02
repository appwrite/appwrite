/**
 * The environment the Console is working in, for everything that is not a React
 * component. An environment is a project of its own underneath, but it has no
 * project ID of its own: it is always the project it belongs to plus an
 * environment ID. `sdk.forProject` reads the active one and sends it as
 * `X-Appwrite-Environment` on every project request, so the whole Console acts
 * in the environment without each hook knowing, and the query cache keys on it
 * so one environment's data never shows in another.
 *
 * It is not a URL parameter: it is a working context, like a selected
 * organization, kept per project in session storage so a reload restores it and
 * different tabs can sit in different environments. The project routes restore
 * it on entry, before any project-scoped request runs.
 */

import { useSyncExternalStore } from 'react'

export interface ActiveEnvironment {
  /** The project the environment belongs to, named in X-Appwrite-Project */
  projectId: string
  environmentId: string
}

let active: ActiveEnvironment | null = null
const listeners = new Set<() => void>()

const STORAGE_PREFIX = 'console:environment:'

function notify(): void {
  for (const listener of listeners) listener()
}

function persist(projectId: string, environmentId: string | null): void {
  if (typeof window === 'undefined') return
  try {
    if (environmentId) {
      window.sessionStorage.setItem(STORAGE_PREFIX + projectId, environmentId)
    } else {
      window.sessionStorage.removeItem(STORAGE_PREFIX + projectId)
    }
  } catch {
    /* private mode / storage disabled */
  }
}

function read(projectId: string): string | null {
  if (typeof window === 'undefined') return null
  try {
    return window.sessionStorage.getItem(STORAGE_PREFIX + projectId) || null
  } catch {
    return null
  }
}

function apply(next: ActiveEnvironment | null): void {
  if (
    next?.projectId === active?.projectId &&
    next?.environmentId === active?.environmentId
  ) {
    return
  }
  active = next
  notify()
}

/**
 * Enter, or with no environment ID leave, the environment of a project. Persists
 * the choice so the project routes restore it on the next entry and after a reload.
 */
export function setActiveEnvironment(
  projectId: string,
  environmentId: string | null | undefined,
): void {
  const id = environmentId || null
  if (projectId) persist(projectId, id)
  apply(id && projectId ? { projectId, environmentId: id } : null)
}

/**
 * Make the active environment the one saved for this project, or none. Called by
 * the project routes on entry, before project-scoped requests run, so the store
 * always matches the project the Console is in.
 */
export function restoreActiveEnvironment(projectId: string): void {
  const id = projectId ? read(projectId) : null
  apply(id && projectId ? { projectId, environmentId: id } : null)
}

export function getActiveEnvironment(): ActiveEnvironment | null {
  return active
}

/** The active environment's ID when it belongs to `projectId`, else null. */
export function getActiveEnvironmentId(projectId: string): string | null {
  return active?.projectId === projectId ? active.environmentId : null
}

/**
 * What tells one environment's cache from another's: a prefix for every query
 * key hash, empty outside an environment so production keys stay as they are.
 */
export function activeEnvironmentCacheScope(): string {
  return active ? `${active.projectId}/${active.environmentId}` : ''
}

export function subscribeActiveEnvironment(listener: () => void): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

function getServerSnapshot(): string | null {
  return null
}

/** The active environment's ID for `projectId`, re-rendering when it changes. */
export function useActiveEnvironmentId(
  projectId: string | undefined,
): string | null {
  return useSyncExternalStore(
    subscribeActiveEnvironment,
    () => (projectId ? getActiveEnvironmentId(projectId) : null),
    getServerSnapshot,
  )
}
