/**
 * Themes that use a dark UI chrome (Monaco, schema export SVG, etc.).
 * next-themes sets a single class on <html> (e.g. dark, classic, crazy).
 */
const DARK_CHROME_CLASSES = [
  'dark',
  'classic',
  'crazy',
  'stealth',
  'premium',
] as const

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
    resolvedTheme === 'stealth' ||
    resolvedTheme === 'premium'
  )
}

/**
 * Standard light/dark only (including when theme is "system" and resolves to light or dark).
 * Custom console themes (crazy, stealth, classic) return false.
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
 * theme primary on crazy, stealth, classic so the logo matches each palette.
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
    effective === 'classic' ||
    effective === 'premium'
  ) {
    return 'text-primary'
  }
  return 'text-[var(--brand-cta)]'
}
