import { useEffect, useState } from 'react'
import { useTheme } from 'next-themes'
import {
  isLegacyTheme,
  isLegacyThemeFromStorage,
} from '@/lib/legacy-theme-assets'

export function useIsLegacyTheme(): boolean {
  const [mounted, setMounted] = useState(false)
  const { theme, resolvedTheme } = useTheme()

  useEffect(() => {
    setMounted(true)
  }, [])

  if (!mounted) {
    if (typeof window !== 'undefined') {
      return isLegacyThemeFromStorage()
    }
    return false
  }
  return isLegacyTheme(theme, resolvedTheme)
}
