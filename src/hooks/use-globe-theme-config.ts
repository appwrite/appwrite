import { useEffect, useState, useSyncExternalStore } from 'react'
import { buildInitGlobeConfig } from '@/lib/init/init-globe-theme'
import {
  getHtmlThemeKey,
  isResolvedThemeDarkChrome,
} from '@/lib/html-theme'
import type { GlobeConfig } from '@/components/ui/globe'

function subscribeToTheme(onStoreChange: () => void) {
  const observer = new MutationObserver(onStoreChange)
  observer.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['class'],
  })

  const media = window.matchMedia('(prefers-color-scheme: dark)')
  media.addEventListener('change', onStoreChange)

  return () => {
    observer.disconnect()
    media.removeEventListener('change', onStoreChange)
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
