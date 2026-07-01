import { useCallback } from 'react'
import {
  applyFaviconVariant,
  FAVICON_MAP,
  variantFromPathname,
  type FaviconVariant,
} from '@/lib/favicon'

export type { FaviconVariant } from '@/lib/favicon'

/**
 * Hook to change the favicon dynamically
 * @returns Function to set the favicon variant
 */
export function useFavicon() {
  const setFavicon = useCallback((variant: FaviconVariant) => {
    if (typeof window === 'undefined') return

    if (!FAVICON_MAP[variant]) {
      console.warn(`Unknown favicon variant: ${variant}`)
      return
    }

    applyFaviconVariant(variant)
  }, [])

  const getCurrentFavicon = useCallback((): FaviconVariant | null => {
    if (typeof window === 'undefined') return null

    const faviconLink = document.querySelector(
      "link[rel='icon']",
    ) as HTMLLinkElement
    if (!faviconLink) return null

    const currentPath = faviconLink.href
    let pathname: string
    try {
      pathname = new URL(currentPath).pathname
    } catch {
      pathname = currentPath
    }

    return variantFromPathname(pathname)
  }, [])

  return {
    setFavicon,
    getCurrentFavicon,
  }
}
