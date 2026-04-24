/**
 * True when the app uses the theme-aware favicon base (`/logo-theme.svg`),
 * matching {@link DynamicFavicon}: localhost, loopback, or Vite dev.
 * Production uses `/logo.svg` instead.
 */
export function usesThemeAwareFaviconHost(): boolean {
  if (typeof window === 'undefined') return false
  return (
    window.location.hostname === 'localhost' ||
    window.location.hostname === '127.0.0.1' ||
    import.meta.env.DEV
  )
}
