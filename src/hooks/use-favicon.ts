import { useCallback } from 'react'

export type FaviconVariant =
  | 'default'
  | 'green'
  | 'orange'
  | 'red'
  | 'theme'
  | 'theme-green'
  | 'theme-orange'
  | 'theme-red'

const FAVICON_MAP: Record<FaviconVariant, string> = {
  default: '/logo.svg',
  green: '/logo-green.svg',
  orange: '/logo-orange.svg',
  red: '/logo-red.svg',
  theme: '/logo-theme.svg',
  'theme-green': '/logo-theme-green.svg',
  'theme-orange': '/logo-theme-orange.svg',
  'theme-red': '/logo-theme-red.svg',
}

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

    // Find existing favicon link or create a new one
    let faviconLink = document.querySelector(
      "link[rel='icon']",
    ) as HTMLLinkElement

    if (!faviconLink) {
      faviconLink = document.createElement('link')
      faviconLink.rel = 'icon'
      faviconLink.type = 'image/svg+xml'
      document.head.appendChild(faviconLink)
    }

    faviconLink.href = faviconPath
  }, [])

  const getCurrentFavicon = useCallback((): FaviconVariant | null => {
    if (typeof window === 'undefined') return null

    const faviconLink = document.querySelector(
      "link[rel='icon']",
    ) as HTMLLinkElement
    if (!faviconLink) return null

    const currentPath = faviconLink.href
    const pathname = new URL(currentPath).pathname

    // Find which variant matches the current path
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
