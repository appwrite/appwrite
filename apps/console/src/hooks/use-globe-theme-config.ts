import { useEffect, useState, useSyncExternalStore } from 'react'
import { buildInitGlobeConfig } from '@/lib/init/init-globe-theme'
import {
  getHtmlThemeKey,
  isResolvedThemeDarkChrome,
} from '@/lib/html-theme'
import type { GlobeConfig } from '@/components/ui/globe-types'

function subscribeToTheme(onStoreChange: () => void) {
  const observer = new MutationObserver(onStoreChange)
  observer.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['class'],
  })

  return () => {
    observer.disconnect()
  }
}

export function useGlobeThemeConfig(): {
  config: GlobeConfig | null
  themeKey: string
} {
  const [mounted, setMounted] = useState(false)

  useEffect(() => {
    setMounted(true)
  }, [])

  const themeKey = useSyncExternalStore(
    subscribeToTheme,
    () => (mounted ? getHtmlThemeKey() : 'pending'),
    () => 'pending',
  )

  const config: GlobeConfig | null =
    mounted && themeKey !== 'pending'
      ? buildInitGlobeConfig(isResolvedThemeDarkChrome(themeKey))
      : null

  return { config, themeKey }
}
