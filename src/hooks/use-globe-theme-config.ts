import { useEffect, useState } from 'react'
import { useTheme } from 'next-themes'
import { buildInitGlobeConfig } from '@/lib/init/init-globe-theme'
import type { GlobeConfig } from '@/components/ui/globe'

export function useGlobeThemeConfig(): {
  config: GlobeConfig | null
  themeKey: string
} {
  const { resolvedTheme } = useTheme()
  const [mounted, setMounted] = useState(false)
  const [config, setConfig] = useState<GlobeConfig | null>(null)

  useEffect(() => {
    setMounted(true)
  }, [])

  useEffect(() => {
    if (!mounted) return

    let cancelled = false
    const isDark = resolvedTheme === 'dark'

    const syncConfig = () => {
      if (!cancelled) {
        setConfig(buildInitGlobeConfig(isDark))
      }
    }

    const frame = requestAnimationFrame(() => {
      requestAnimationFrame(syncConfig)
    })

    return () => {
      cancelled = true
      cancelAnimationFrame(frame)
    }
  }, [mounted, resolvedTheme])

  const themeKey = !mounted ? 'pending' : resolvedTheme === 'dark' ? 'dark' : 'light'

  return { config, themeKey }
}
