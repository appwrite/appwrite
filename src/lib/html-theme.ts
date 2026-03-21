/**
 * Themes that use a dark UI chrome (Monaco, schema export SVG, etc.).
 * next-themes sets a single class on <html> (e.g. dark, classic, crazy).
 */
const DARK_CHROME_CLASSES = ['dark', 'classic', 'crazy', 'stealth'] as const

export function isHtmlDarkChrome(): boolean {
  if (typeof document === 'undefined') return false
  const list = document.documentElement.classList
  return DARK_CHROME_CLASSES.some((c) => list.contains(c))
}

/** next-themes `resolvedTheme` after system resolution (custom themes keep their name). */
export function isResolvedThemeDarkChrome(
  resolvedTheme: string | undefined,
): boolean {
  if (!resolvedTheme) return false
  if (resolvedTheme === 'dark') return true
  return (
    resolvedTheme === 'classic' ||
    resolvedTheme === 'crazy' ||
    resolvedTheme === 'stealth'
  )
}
