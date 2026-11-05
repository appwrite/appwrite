import { useEffect, useState } from 'react'
import { useTheme } from 'next-themes'
import { isLegacyTheme, LEGACY_LOGO_SRC } from '@/lib/legacy-theme-assets'

export function AppwriteLogo({ className }: { className?: string }) {
  const [mounted, setMounted] = useState(false)
  const { theme, resolvedTheme } = useTheme()

  useEffect(() => {
    setMounted(true)
  }, [])

  if (mounted && isLegacyTheme(theme, resolvedTheme)) {
    return <img src={LEGACY_LOGO_SRC} alt="Appwrite" className={className} />
  }

  // Determine which logo to use based on theme
  // appwrite-light.svg has dark fill (#19191C) - use on light backgrounds
  // appwrite-dark.svg has light fill (#EDEDF0) - use on dark backgrounds
  // Default to dark mode if theme is not yet resolved (matches defaultTheme="dark" in ThemeProvider)
  const isDark = mounted ? (resolvedTheme ?? theme) === 'dark' : true // Default to dark during SSR/initial render
  const logoSrc = isDark ? '/appwrite-dark.svg' : '/appwrite-light.svg'

  return <img src={logoSrc} alt="Appwrite" className={className} />
}
