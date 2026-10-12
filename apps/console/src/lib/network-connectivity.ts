import { useEffect, useState, useSyncExternalStore } from 'react'

const CONNECTIVITY_PROBE_URL = '/logo-theme.svg'
const PROBE_TIMEOUT_MS = 5000
const PROBE_INTERVAL_MS = 10000

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

async function probeLocalReachable(): Promise<boolean> {
  if (typeof window === 'undefined') return true
  try {
    const response = await fetch(CONNECTIVITY_PROBE_URL, {
      method: 'HEAD',
      cache: 'no-store',
      signal: AbortSignal.timeout(PROBE_TIMEOUT_MS),
    })
    return response.ok
  } catch {
    return false
  }
}

/**
 * True only when the browser reports offline and a same-origin probe fails.
 * `navigator.onLine` is unreliable (VPNs, captive portals, some browsers); probing
 * avoids false "You're offline" curtains while the dev server or network still works.
 */
export function useConfirmedOffline(): boolean {
  const navigatorOnline = useNavigatorOnline()
  const [reachable, setReachable] = useState(true)

  useEffect(() => {
    if (navigatorOnline) {
      setReachable(true)
      return
    }

    let cancelled = false

    const check = async () => {
      const ok = await probeLocalReachable()
      if (!cancelled) setReachable(ok)
    }

    void check()
    const interval = window.setInterval(() => {
      void check()
    }, PROBE_INTERVAL_MS)

    return () => {
      cancelled = true
      window.clearInterval(interval)
    }
  }, [navigatorOnline])

  return !navigatorOnline && !reachable
}

export function isNavigatorReportedOffline(): boolean {
  return typeof navigator !== 'undefined' && !navigator.onLine
}
