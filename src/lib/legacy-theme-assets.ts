export const LEGACY_ICON_SRC = '/legacy-icon.svg'
export const LEGACY_LOGO_SRC = '/legacy-logo.svg'

export function resolveEffectiveTheme(
  theme: string | undefined,
  resolvedTheme: string | undefined,
): string | undefined {
  return theme === 'system' ? resolvedTheme : theme
}

export function isLegacyTheme(
  theme: string | undefined,
  resolvedTheme: string | undefined,
): boolean {
  return resolveEffectiveTheme(theme, resolvedTheme) === 'legacy'
}

/** Matches THEME_SCRIPT / first-paint theme class on `<html>`. */
export function readStoredThemePreference(): string {
  if (typeof window === 'undefined') return 'system'
  try {
    const stored = localStorage.getItem('theme') || 'system'
    return stored === 'classic' ? 'dark' : stored
  } catch {
    return 'system'
  }
}

export function readEffectiveThemeFromStorage(): string {
  const preference = readStoredThemePreference()
  if (preference === 'system') {
    if (typeof window === 'undefined') return 'light'
    return window.matchMedia('(prefers-color-scheme: dark)').matches
      ? 'dark'
      : 'light'
  }
  return preference
}

export function isLegacyThemeFromStorage(): boolean {
  return readEffectiveThemeFromStorage() === 'legacy'
}
