import { useEffect, useState } from 'react'

export function DynamicFavicon() {
  const [mounted, setMounted] = useState(false)

  useEffect(() => {
    setMounted(true)
  }, [])

  useEffect(() => {
    // Only update favicon on localhost
    if (typeof window === 'undefined' || !mounted) return
    
    const isLocalhost = 
      window.location.hostname === 'localhost' || 
      window.location.hostname === '127.0.0.1' ||
      import.meta.env.DEV

    if (!isLocalhost) return
    
    // Find existing favicon link or create a new one
    let faviconLink = document.querySelector("link[rel='icon']") as HTMLLinkElement
    
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

