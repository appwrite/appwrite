export const LEGACY_ICON_SRC = '/legacy-icon.svg'
export const LEGACY_LOGO_SRC = '/legacy-logo.svg'

export function resolveEffectiveTheme(
  theme: string | undefined,
  resolvedTheme: string | undefined,
): string | undefined {
  return theme === 'system' ? (resolvedTheme ?? 'dark') : theme
}

export function isLegacyTheme(
  theme: string | undefined,
  resolvedTheme: string | undefined,
): boolean {
  return resolveEffectiveTheme(theme, resolvedTheme) === 'legacy'
}

/** Matches THEME_SCRIPT / first-paint theme class on `<html>`. */
export function readStoredThemePreference(): string {
  if (typeof window === 'undefined') return 'dark'
  try {
    const stored = localStorage.getItem('theme') || 'dark'
    return stored === 'classic' || stored === 'system' ? 'dark' : stored
  } catch {
    return 'dark'
  }
}

export function readEffectiveThemeFromStorage(): string {
  return readStoredThemePreference()
}

export function isLegacyThemeFromStorage(): boolean {
  return readEffectiveThemeFromStorage() === 'legacy'
}
