import { useEffect, useState } from 'react'
import { applyFaviconHref } from '@/lib/favicon'
import { usesThemeAwareFaviconHost } from '@/lib/utils/theme-favicon-host'

export function DynamicFavicon() {
  const [mounted, setMounted] = useState(false)

  useEffect(() => {
    setMounted(true)
  }, [])

  useEffect(() => {
    if (typeof window === 'undefined' || !mounted) return
    if (!usesThemeAwareFaviconHost()) return

    applyFaviconHref('/logo-theme.svg')
  }, [mounted])

  return null
}
