import { useEffect, useState } from 'react'
import { usesThemeAwareFaviconHost } from '@/lib/utils/theme-favicon-host'

export function DynamicFavicon() {
  const [mounted, setMounted] = useState(false)

  useEffect(() => {
    setMounted(true)
  }, [])

  useEffect(() => {
    // Theme-aware favicon only where we use `/logo-theme.svg` as the base
    if (typeof window === 'undefined' || !mounted) return

    if (!usesThemeAwareFaviconHost()) return

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

    // Use the theme-aware logo that responds to media queries
    faviconLink.href = '/logo-theme.svg'
  }, [mounted])

  return null
}
