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

  useEffect(() => {
    setMounted(true)
  }, [])

  const themeKey = !mounted ? 'pending' : resolvedTheme === 'dark' ? 'dark' : 'light'
  const config: GlobeConfig | null = mounted
    ? buildInitGlobeConfig(resolvedTheme === 'dark')
    : null

  return { config, themeKey }
}
