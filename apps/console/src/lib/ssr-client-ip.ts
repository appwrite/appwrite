import { createIsomorphicFn } from '@tanstack/react-start'
import { getRequest, getRequestIP } from '@tanstack/react-start/server'
import {
  getClientIpSnapshotFromRequest,
  SSR_CLIENT_IP_WINDOW_KEY,
  type ClientIpSnapshot,
} from '@/lib/client-ip'

/**
 * SSR snapshot from the document request. Server APIs stay inside `.server()`
 * so the client bundle does not import `@tanstack/react-start/server`.
 */
export const getSsrClientIpSnapshot = createIsomorphicFn()
  .server((): ClientIpSnapshot | null => {
    try {
      const runtimeIp = getRequestIP()?.trim() || null
      return getClientIpSnapshotFromRequest(getRequest(), runtimeIp)
    } catch {
      return null
    }
  })
  .client((): ClientIpSnapshot | null => {
    return window[SSR_CLIENT_IP_WINDOW_KEY] ?? null
  })

export function getSsrClientIpScript(): string {
  return `window.${SSR_CLIENT_IP_WINDOW_KEY}=${JSON.stringify(getSsrClientIpSnapshot())}`
}

