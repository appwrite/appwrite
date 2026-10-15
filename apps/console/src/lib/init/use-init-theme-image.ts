import { isHtmlDarkChrome, isResolvedThemeDarkChrome } from '@/lib/html-theme'
import { useTheme } from 'next-themes'
import { useEffect, useState } from 'react'

/** Whether Init marketing images should use the dark variant (after mount). */
export function useInitThemeUsesDarkImage() {
  const { resolvedTheme } = useTheme()
  const [mounted, setMounted] = useState(false)

  useEffect(() => {
    setMounted(true)
  }, [])

  if (!mounted) return false

  if (resolvedTheme === 'light') return false
  if (resolvedTheme === 'dark') return true
  if (!resolvedTheme) return isHtmlDarkChrome()
  if (isResolvedThemeDarkChrome(resolvedTheme)) return true
  return isHtmlDarkChrome()
}

export function useInitThemeImageSrc(imageSrcLight: string, imageSrcDark: string) {
  const usesDarkImage = useInitThemeUsesDarkImage()
  return usesDarkImage ? imageSrcDark : imageSrcLight
}
