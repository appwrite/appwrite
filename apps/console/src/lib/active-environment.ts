/**
 * The environment the Console is working in, per project, for everything that is
 * not a React component. An environment is a project of its own underneath, but
 * it has no project ID of its own: it is always the project it belongs to plus
 * an environment ID. `sdk.forProject` reads the one for that project and sends
 * it as `X-Appwrite-Environment` on every request, so the whole Console acts in
 * the environment without each hook knowing.
 *
 * It is kept per project, not as a single "active" value: route intent-preload
 * runs another project's `beforeLoad` while the user is still in the current
 * one, so a single global would be clobbered and the current project's requests
 * would lose their environment. It is not a URL parameter either, but a working
 * context like a selected organization, persisted per project in session storage
 * so a reload restores it and different tabs can sit in different environments.
 *
 * An environment and the project it belongs to share the project's ID, so they
 * share every React Query key. Rather than scope the keys — which would leave
 * invalidation and realtime merges matching across environments on the raw key —
 * only one environment's data for a project is ever cached at a time: changing
 * the environment of a project drops that project's queries so the new context
 * refetches. The app registers that drop through `onChange`.
 */

import { useSyncExternalStore } from 'react'

/** projectId → environmentId the Console is in for that project */
const environments = new Map<string, string>()
const listeners = new Set<() => void>()
const STORAGE_PREFIX = 'console:environment:'

/** Called when a project's environment changes, so the app can drop its queries. */
let onChange: ((projectId: string) => void) | null = null
export function registerActiveEnvironmentChange(
  handler: (projectId: string) => void,
): void {
  onChange = handler
}

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

function set(projectId: string, environmentId: string | null): void {
  if (!projectId) return
  const previous = environments.get(projectId) ?? null
  const next = environmentId || null
  if (previous === next) return

  if (next) {
    environments.set(projectId, next)
  } else {
    environments.delete(projectId)
  }
  notify()
  // The other environment's data must not linger in the shared project keys.
  onChange?.(projectId)
}

/**
 * Enter, or with no environment ID leave, the environment of a project, and
 * persist the choice so the project routes restore it on the next entry.
 */
export function setActiveEnvironment(
  projectId: string,
  environmentId: string | null | undefined,
): void {
  persist(projectId, environmentId || null)
  set(projectId, environmentId || null)
}

/**
 * Make the project's environment the one saved for it, or none. Called by the
 * project routes on entry, before project-scoped requests run — only for the
 * project being entered, so preloading another project never clobbers this one.
 */
export function restoreActiveEnvironment(projectId: string): void {
  set(projectId, read(projectId))
}

/** The environment ID the Console is in for `projectId`, or null for production. */
export function getActiveEnvironmentId(projectId: string): string | null {
  return projectId ? (environments.get(projectId) ?? null) : null
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

/** The environment ID the Console is in for `projectId`, re-rendering on change. */
export function useActiveEnvironmentId(
  projectId: string | undefined,
): string | null {
  return useSyncExternalStore(
    subscribeActiveEnvironment,
    () => (projectId ? getActiveEnvironmentId(projectId) : null),
    getServerSnapshot,
  )
}
