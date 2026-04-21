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

    // Browsers (Chrome especially) cache favicons aggressively and frequently
    // ignore in-place `link.href` mutations - the new icon only shows up after
    // an unrelated update like a tab title change. Removing all existing
    // <link rel="icon"> elements and inserting a fresh one (with a cache-
    // busting query string) reliably forces an immediate refresh.
    const existing = document.querySelectorAll(
      "link[rel='icon'], link[rel='shortcut icon']",
    )
    existing.forEach((node) => node.parentNode?.removeChild(node))

    const link = document.createElement('link')
    link.rel = 'icon'
    link.type = 'image/svg+xml'
    link.href = `${faviconPath}?v=${Date.now()}`
    document.head.appendChild(link)
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
