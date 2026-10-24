import type { Models } from '@appwrite.io/console'

const GLOBAL_CACHE_KEY = '__vibesConsoleAccountCache'

/** Window event fired when a cached account is set or cleared. */
export const CONSOLE_ACCOUNT_CHANGE_EVENT = 'consoleAccountChange'

function notifyConsoleAccountChange(): void {
  if (typeof window === 'undefined') return
  window.dispatchEvent(new CustomEvent(CONSOLE_ACCOUNT_CHANGE_EVENT))
}

type ConsoleAccountGlobalCache = {
  resolved: Map<number, Models.User>
  inflight: Map<number, Promise<Models.User>>
  unauthenticated: Map<number, unknown>
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
    }
  }
  return g[GLOBAL_CACHE_KEY]
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
  notifyConsoleAccountChange()
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

export function clearConsoleAccountUnauthenticatedError(revision?: number): void {
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
  } else {
    cache.resolved.delete(revision)
    cache.inflight.delete(revision)
    cache.unauthenticated.delete(revision)
  }
  notifyConsoleAccountChange()
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
