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
