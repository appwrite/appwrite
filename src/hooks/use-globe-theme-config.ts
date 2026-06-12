import { useEffect, useState, useSyncExternalStore } from 'react'
import { buildInitGlobeConfig } from '@/lib/init/init-globe-theme'
import type { GlobeConfig } from '@/components/ui/globe'

function getResolvedTheme(): 'light' | 'dark' {
  if (typeof document === 'undefined') return 'light'

  const root = document.documentElement
  if (root.classList.contains('dark')) return 'dark'
  if (root.classList.contains('light')) return 'light'

  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

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

  const resolvedTheme = useSyncExternalStore(
    subscribeToTheme,
    () => (mounted ? getResolvedTheme() : 'light'),
    () => 'light',
  )

  const themeKey = !mounted ? 'pending' : resolvedTheme
  const config: GlobeConfig | null = mounted
    ? buildInitGlobeConfig(resolvedTheme === 'dark')
    : null

  return { config, themeKey }
}
