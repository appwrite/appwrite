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
