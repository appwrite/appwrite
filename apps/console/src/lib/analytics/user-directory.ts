/**
 * Resolves the user IDs analytics records (`userId` dimension and filter) to
 * the project's Auth users, so cards, lists and filter chips show people
 * rather than opaque IDs.
 *
 * IDs are collected while rows render and fetched together: one
 * `users.list` per 100 IDs, however many cards and dialogs ask. An ID with no
 * matching user (sent by a server with an API key, or a deleted user) resolves
 * to `null` and stays an ID on screen.
 */

import { useCallback, useSyncExternalStore } from 'react'
import { Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

/** `Query.equal` takes at most 100 values. */
const BATCH_SIZE = 100

/** A resolved user, `null` when no project user has the ID, `undefined` while unknown. */
export type AnalyticsUser = Models.User | null

type Directory = {
  users: Map<string, AnalyticsUser>
  requested: Set<string>
  queued: Set<string>
  scheduled: boolean
  version: number
  listeners: Set<() => void>
}

const directories = new Map<string, Directory>()

function directoryFor(projectId: string): Directory {
  let directory = directories.get(projectId)
  if (!directory) {
    directory = {
      users: new Map(),
      requested: new Set(),
      queued: new Set(),
      scheduled: false,
      version: 0,
      listeners: new Set(),
    }
    directories.set(projectId, directory)
  }
  return directory
}

function publish(directory: Directory, entries: [string, AnalyticsUser][]) {
  for (const [id, user] of entries) directory.users.set(id, user)
  directory.version++
  for (const listener of directory.listeners) listener()
}

async function fetchBatch(projectId: string, ids: string[]) {
  const directory = directoryFor(projectId)
  try {
    const response = await sdk.forProject(projectId).users.list({
      queries: [Query.equal('$id', ids), Query.limit(ids.length)],
      total: false,
    })
    const found = new Map(response.users.map((user) => [user.$id, user]))
    publish(
      directory,
      ids.map((id) => [id, found.get(id) ?? null]),
    )
  } catch {
    // No users.read, or the request failed: show the IDs as they are.
    publish(
      directory,
      ids.map((id) => [id, null]),
    )
  }
}

function flush(projectId: string) {
  const directory = directoryFor(projectId)
  directory.scheduled = false
  const ids = [...directory.queued]
  directory.queued.clear()
  for (let start = 0; start < ids.length; start += BATCH_SIZE) {
    void fetchBatch(projectId, ids.slice(start, start + BATCH_SIZE))
  }
}

function request(projectId: string, id: string) {
  const directory = directoryFor(projectId)
  if (directory.requested.has(id)) return
  directory.requested.add(id)
  directory.queued.add(id)
  if (directory.scheduled) return
  directory.scheduled = true
  // After the render that asked: every row rendered in it joins one request.
  queueMicrotask(() => flush(projectId))
}

/** Resolve one ID now from what is already known, without fetching. */
export function peekAnalyticsUser(
  projectId: string,
  userId: string,
): AnalyticsUser | undefined {
  return directories.get(projectId)?.users.get(userId)
}

/**
 * `lookup(id)` returns the user, `null` for an ID with no user, or `undefined`
 * while it loads (and asks for it). The function changes identity whenever
 * new users arrive, so memoized labels recompute.
 */
export function useAnalyticsUserDirectory(
  projectId: string | null | undefined,
) {
  const subscribe = useCallback(
    (listener: () => void) => {
      if (!projectId) return () => {}
      const directory = directoryFor(projectId)
      directory.listeners.add(listener)
      return () => directory.listeners.delete(listener)
    },
    [projectId],
  )
  const version = useSyncExternalStore(
    subscribe,
    () => (projectId ? directoryFor(projectId).version : 0),
    () => 0,
  )

  return useCallback(
    (userId: string | null | undefined): AnalyticsUser | undefined => {
      if (!projectId || !userId) return undefined
      const user = directoryFor(projectId).users.get(userId)
      if (user === undefined) request(projectId, userId)
      return user
    },
    // `version` is the cache generation: a new lookup per batch received.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [projectId, version],
  )
}

/** The user's page in Auth. */
export function analyticsUserHref(projectId: string, userId: string): string {
  return `/projects/${encodeURIComponent(projectId)}/auth/users/${encodeURIComponent(userId)}`
}

/** Name, then email, then phone; the ID for anyone without them. */
export function analyticsUserLabel(
  userId: string,
  user: AnalyticsUser | undefined,
): string {
  return user?.name || user?.email || user?.phone || userId
}
