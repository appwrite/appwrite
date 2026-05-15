import { useSyncExternalStore } from 'react'

function subscribeNavigatorOnline(onStoreChange: () => void) {
  if (typeof window === 'undefined') return () => {}
  const handleChange = () => onStoreChange()
  window.addEventListener('online', handleChange)
  window.addEventListener('offline', handleChange)
  return () => {
    window.removeEventListener('online', handleChange)
    window.removeEventListener('offline', handleChange)
  }
}

function getNavigatorOnlineSnapshot(): boolean {
  return typeof navigator === 'undefined' ? true : navigator.onLine
}

function getNavigatorOnlineServerSnapshot(): boolean {
  return true
}

/** Subscribes to `navigator.onLine` / window `online` & `offline` events. SSR assumes online. */
export function useNavigatorOnline(): boolean {
  return useSyncExternalStore(
    subscribeNavigatorOnline,
    getNavigatorOnlineSnapshot,
    getNavigatorOnlineServerSnapshot,
  )
}

export function isNavigatorReportedOffline(): boolean {
  return typeof navigator !== 'undefined' && !navigator.onLine
}

/**
 * Heuristic for errors that usually indicate the client could not complete an HTTP request
 * (offline, DNS, TLS, proxy, etc.). Excludes user aborts.
 */
export function isLikelyConnectivityFailure(error: unknown): boolean {
  if (!error || typeof error !== 'object') return false
  const err = error as {
    message?: string
    name?: string
    code?: number | string
    status?: number
  }

  if (err.name === 'AbortError') return false

  if (isNavigatorReportedOffline()) return true

  if (err.status === 0) return true

  const message = String(err.message || '')
  const lower = message.toLowerCase()

  if (err.name === 'NetworkError') return true

  if (
    err.name === 'TypeError' &&
    (lower.includes('fetch') || lower.includes('failed to fetch'))
  ) {
    return true
  }

  if (
    lower.includes('failed to fetch') ||
    lower.includes('networkerror') ||
    lower.includes('network request failed') ||
    lower.includes('load failed') ||
    lower.includes('net::err')
  ) {
    return true
  }

  return false
}
