import { usesThemeAwareFaviconHost } from '@/lib/utils/theme-favicon-host'

export type FaviconVariant =
  | 'default'
  | 'green'
  | 'orange'
  | 'red'
  | 'theme'
  | 'theme-green'
  | 'theme-orange'
  | 'theme-red'

export const FAVICON_MAP: Record<FaviconVariant, string> = {
  default: '/logo.svg',
  green: '/logo-green.svg',
  orange: '/logo-orange.svg',
  red: '/logo-red.svg',
  theme: '/logo-theme.svg',
  'theme-green': '/logo-theme-green.svg',
  'theme-orange': '/logo-theme-orange.svg',
  'theme-red': '/logo-theme-red.svg',
}

/**
 * Replace all favicon `<link>` elements with a fresh one. Browsers (especially
 * Chrome) cache favicons aggressively and often ignore in-place `href` updates.
 */
export function applyFaviconHref(
  href: string,
  options?: { type?: string; cacheBust?: boolean },
): void {
  if (typeof document === 'undefined') return

  const type = options?.type ?? 'image/svg+xml'
  const cacheBust = options?.cacheBust ?? true
  const resolvedHref =
    cacheBust && !href.includes('?') ? `${href}?v=${Date.now()}` : href

  const existing = document.querySelectorAll(
    "link[rel='icon'], link[rel='shortcut icon']",
  )
  existing.forEach((node) => node.parentNode?.removeChild(node))

  const link = document.createElement('link')
  link.rel = 'icon'
  link.type = type
  link.href = resolvedHref
  document.head.appendChild(link)
}

export function getDefaultFaviconVariant(): FaviconVariant {
  return usesThemeAwareFaviconHost() ? 'theme' : 'default'
}
