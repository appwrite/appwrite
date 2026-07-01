import { usesThemeAwareFaviconHost } from '@/lib/utils/theme-favicon-host'

export type FaviconVariant =
  | 'default'
  | 'green'
  | 'blue'
  | 'red'
  | 'theme'
  | 'theme-green'
  | 'theme-blue'
  | 'theme-red'

type ThemeStatusVariant = 'theme-green' | 'theme-blue' | 'theme-red'

/** Base paths; status variants resolve to PNGs via {@link resolveFaviconHref}. */
export const FAVICON_MAP: Record<FaviconVariant, string> = {
  default: '/logo.svg',
  green: '/favicons/logo-green.png',
  blue: '/favicons/logo-blue.png',
  red: '/favicons/logo-red.png',
  theme: '/logo-theme.svg',
  'theme-green': '/favicons/logo-theme-green-light.png',
  'theme-blue': '/favicons/logo-theme-blue-light.png',
  'theme-red': '/favicons/logo-theme-red-light.png',
}

const THEME_STATUS_FAVICONS: Record<
  ThemeStatusVariant,
  { light: string; dark: string }
> = {
  'theme-green': {
    light: '/favicons/logo-theme-green-light.png',
    dark: '/favicons/logo-theme-green-dark.png',
  },
  'theme-blue': {
    light: '/favicons/logo-theme-blue-light.png',
    dark: '/favicons/logo-theme-blue-dark.png',
  },
  'theme-red': {
    light: '/favicons/logo-theme-red-light.png',
    dark: '/favicons/logo-theme-red-dark.png',
  },
}

const STATUS_VARIANTS = new Set<FaviconVariant>([
  'green',
  'blue',
  'red',
  'theme-green',
  'theme-blue',
  'theme-red',
])

let activeStatusVariant: FaviconVariant | null = null

function isThemeStatusVariant(variant: FaviconVariant): variant is ThemeStatusVariant {
  return variant in THEME_STATUS_FAVICONS
}

function prefersDarkColorScheme(): boolean {
  if (typeof window === 'undefined') return false
  return window.matchMedia('(prefers-color-scheme: dark)').matches
}

function faviconMimeType(href: string): string {
  return href.endsWith('.png') ? 'image/png' : 'image/svg+xml'
}

export function resolveFaviconHref(variant: FaviconVariant): {
  href: string
  type: string
} {
  if (isThemeStatusVariant(variant)) {
    const paths = THEME_STATUS_FAVICONS[variant]
    const href = prefersDarkColorScheme() ? paths.dark : paths.light
    return { href, type: 'image/png' }
  }

  const href = FAVICON_MAP[variant]
  return { href, type: faviconMimeType(href) }
}

export function variantFromPathname(pathname: string): FaviconVariant | null {
  for (const [variant, path] of Object.entries(FAVICON_MAP)) {
    if (pathname.endsWith(path)) {
      return variant as FaviconVariant
    }
  }

  for (const [variant, paths] of Object.entries(THEME_STATUS_FAVICONS)) {
    if (
      pathname.endsWith(paths.light) ||
      pathname.endsWith(paths.dark)
    ) {
      return variant as FaviconVariant
    }
  }

  return null
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

  const type = options?.type ?? faviconMimeType(href)
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

export function applyFaviconVariant(
  variant: FaviconVariant,
  options?: { cacheBust?: boolean },
): void {
  activeStatusVariant = STATUS_VARIANTS.has(variant) ? variant : null
  const { href, type } = resolveFaviconHref(variant)
  applyFaviconHref(href, { type, cacheBust: options?.cacheBust })
}

export function getDefaultFaviconVariant(): FaviconVariant {
  return usesThemeAwareFaviconHost() ? 'theme' : 'default'
}

if (typeof window !== 'undefined') {
  window
    .matchMedia('(prefers-color-scheme: dark)')
    .addEventListener('change', () => {
      if (activeStatusVariant && isThemeStatusVariant(activeStatusVariant)) {
        applyFaviconVariant(activeStatusVariant)
      }
    })
}
