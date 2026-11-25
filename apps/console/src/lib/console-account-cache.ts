import type { Models } from '@appwrite.io/console'

const GLOBAL_CACHE_KEY = '__vibesConsoleAccountCache'

type ConsoleAccountGlobalCache = {
  resolved: Map<number, Models.User>
  inflight: Map<number, Promise<Models.User>>
  unauthenticated: Map<number, unknown>
  /** Bumped by every full clear: sign-out, impersonation, account deletion. */
  session: number
}

function getGlobalCache(): ConsoleAccountGlobalCache {
  const g = globalThis as typeof globalThis & {
    [GLOBAL_CACHE_KEY]?: ConsoleAccountGlobalCache
  }
  if (!g[GLOBAL_CACHE_KEY]) {
    g[GLOBAL_CACHE_KEY] = {
      resolved: new Map(),
      inflight: new Map(),
      unauthenticated: new Map(),
      session: 0,
    }
  }
  return g[GLOBAL_CACHE_KEY]
}

/**
 * Which sign-in the cached account belongs to. It changes whenever the whole
 * cache is cleared, so signing out and back into the same account is a new one.
 */
export function getConsoleAccountSession(): number {
  return getGlobalCache().session
}

export function getConsoleAccountSync(
  revision: number,
): Models.User | undefined {
  return getGlobalCache().resolved.get(revision)
}

export function setConsoleAccountCache(
  account: Models.User,
  revision: number,
): void {
  getGlobalCache().resolved.set(revision, account)
}

export function getConsoleAccountUnauthenticatedError(
  revision: number,
): unknown | undefined {
  return getGlobalCache().unauthenticated.get(revision)
}

export function setConsoleAccountUnauthenticatedError(
  revision: number,
  error: unknown,
): void {
  getGlobalCache().unauthenticated.set(revision, error)
}

export function clearConsoleAccountUnauthenticatedError(
  revision?: number,
): void {
  const cache = getGlobalCache()
  if (revision === undefined) {
    cache.unauthenticated.clear()
    return
  }
  cache.unauthenticated.delete(revision)
}

export function clearConsoleAccountCache(revision?: number): void {
  const cache = getGlobalCache()
  if (revision === undefined) {
    cache.resolved.clear()
    cache.inflight.clear()
    cache.unauthenticated.clear()
    cache.session += 1
    return
  }
  cache.resolved.delete(revision)
  cache.inflight.delete(revision)
  cache.unauthenticated.delete(revision)
}

export function getConsoleAccountInflight(
  revision: number,
): Promise<Models.User> | undefined {
  return getGlobalCache().inflight.get(revision)
}

export function setConsoleAccountInflight(
  revision: number,
  promise: Promise<Models.User>,
): void {
  getGlobalCache().inflight.set(revision, promise)
}

export function clearConsoleAccountInflight(
  revision: number,
  promise: Promise<Models.User>,
): void {
  const cache = getGlobalCache()
  if (cache.inflight.get(revision) === promise) {
    cache.inflight.delete(revision)
  }
}
