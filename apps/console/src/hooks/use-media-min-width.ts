import { useSyncExternalStore } from 'react'

/**
 * Subscribes to `window.matchMedia('(min-width: Npx)')` for responsive layout.
 * SSR snapshot is `false` (narrow / mobile-first) to match `lg:hidden`-style UIs.
 */
export function useMediaMinWidth(minWidthPx: number): boolean {
  const query = `(min-width: ${minWidthPx}px)`
  return useSyncExternalStore(
    (onStoreChange) => {
      if (typeof window === 'undefined') return () => {}
      const mq = window.matchMedia(query)
      mq.addEventListener('change', onStoreChange)
      return () => mq.removeEventListener('change', onStoreChange)
    },
    () =>
      typeof window !== 'undefined' ? window.matchMedia(query).matches : false,
    () => false,
  )
}
