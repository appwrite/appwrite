import { useCallback } from 'react'
import {
  applyFaviconHref,
  FAVICON_MAP,
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

    const faviconPath = FAVICON_MAP[variant]
    if (!faviconPath) {
      console.warn(`Unknown favicon variant: ${variant}`)
      return
    }

    applyFaviconHref(faviconPath)
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

    for (const [variant, path] of Object.entries(FAVICON_MAP)) {
      if (pathname.endsWith(path)) {
        return variant as FaviconVariant
      }
    }

    return null
  }, [])

  return {
    setFavicon,
    getCurrentFavicon,
  }
}
