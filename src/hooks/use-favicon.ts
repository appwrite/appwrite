import { useCallback } from 'react'
import {
  applyFaviconVariant,
  FAVICON_MAP,
  getFaviconStatus,
  subscribeFaviconStatus,
  type FaviconApplyMeta,
  type FaviconStatus,
  type FaviconVariant,
} from '@/lib/favicon'

export type { FaviconVariant, FaviconApplyMeta, FaviconStatus } from '@/lib/favicon'

/**
 * Hook to change the favicon dynamically
 * @returns Function to set the favicon variant
 */
export function useFavicon() {
  const setFavicon = useCallback(
    (variant: FaviconVariant, meta?: FaviconApplyMeta) => {
      if (typeof window === 'undefined') return

      if (!FAVICON_MAP[variant]) {
        console.warn(`Unknown favicon variant: ${variant}`)
        return
      }

      applyFaviconVariant(variant, meta)
    },
    [],
  )

  const getCurrentFavicon = useCallback((): FaviconVariant | null => {
    if (typeof window === 'undefined') return null
    return getFaviconStatus().variant
  }, [])

  return {
    setFavicon,
    getCurrentFavicon,
    getFaviconStatus,
    subscribeFaviconStatus,
  }
}
