/**
 * Themes that use a dark UI chrome (Monaco, schema export SVG, etc.).
 * next-themes sets a single class on <html> (e.g. dark, crazy).
 */
const DARK_CHROME_CLASSES = [
  'dark',
  'crazy',
  'stealth',
  'premium',
  'high-contrast',
] as const

/** Theme class names next-themes sets on `<html>` (excludes `system`). */
export const HTML_THEME_CLASSES = [
  'light',
  'dark',
  'crazy',
  'stealth',
  'premium',
  'high-contrast',
  'barbie',
  'nineties',
  'legacy',
] as const

/** Active theme class on `<html>`, or light/dark from system preference when unset. */
export function getHtmlThemeKey(): string {
  if (typeof document === 'undefined') return 'light'

  const root = document.documentElement
  for (const theme of HTML_THEME_CLASSES) {
    if (root.classList.contains(theme)) return theme
  }

  return window.matchMedia('(prefers-color-scheme: dark)').matches
    ? 'dark'
    : 'light'
}

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
    resolvedTheme === 'crazy' ||
    resolvedTheme === 'stealth' ||
    resolvedTheme === 'premium' ||
    resolvedTheme === 'high-contrast'
  )
}

/**
 * Standard light/dark only (including when theme is "system" and resolves to light or dark).
 * Custom console themes (crazy, stealth, etc.) return false.
 */
export function isStandardLightOrDarkTheme(
  theme: string | undefined,
  resolvedTheme: string | undefined,
): boolean {
  const effective = theme === 'system' ? resolvedTheme : theme
  return effective === 'light' || effective === 'dark'
}

/**
 * Tailwind classes for the header Appwrite mark: brand pink on light/dark (and system→light/dark);
 * theme primary on crazy, stealth, premium, etc. so the logo matches each palette.
 */
export function getConsoleHeaderLogoClass(
  theme: string | undefined,
  resolvedTheme: string | undefined,
  mounted: boolean,
): string {
  if (!mounted) {
    return 'text-[var(--brand-cta)]'
  }
  const effective = theme === 'system' ? resolvedTheme : theme
  if (effective === 'light' || effective === 'dark') {
    return 'text-[var(--brand-cta)]'
  }
  if (
    effective === 'crazy' ||
    effective === 'stealth' ||
    effective === 'premium' ||
    effective === 'high-contrast' ||
    effective === 'barbie' ||
    effective === 'nineties'
  ) {
    return 'text-primary'
  }
  return 'text-[var(--brand-cta)]'
}
