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

/**
 * Who last applied the tab favicon. Used by the debug menu so a blue/green/red
 * status dot can be traced back to its trigger.
 */
export type FaviconStatusSource =
  | 'default'
  | 'debug-menu'
  | 'build-notifications'
  | 'agent-conversation'
  | 'legacy-theme'
  | 'dynamic-favicon'
  | 'unknown'

export type FaviconApplyMeta = {
  source?: FaviconStatusSource
  /** Short human-readable explanation of why this variant is active. */
  reason?: string
  /** Optional extra detail (IDs, counts, status strings). */
  detail?: string
  /** Structured debug context (project, resources, conversation). */
  context?: FaviconStatusContext
  /**
   * Explicit variant when applying a raw href that is not in {@link FAVICON_MAP}
   * (e.g. legacy theme icon).
   */
  variant?: FaviconVariant
}

/** Structured fields for the debug favicon status panel. */
export type FaviconStatusResource = {
  type: string
  id: string
  name?: string
  status?: string
  deploymentId?: string
}

export type FaviconStatusContext = {
  projectId?: string
  projectName?: string
  organizationId?: string
  conversationId?: string
  pathname?: string
  resources?: FaviconStatusResource[]
  /** Free-form key/value rows for source-specific fields. */
  fields?: Array<{ label: string; value: string }>
}

export type FaviconStatus = {
  variant: FaviconVariant
  source: FaviconStatusSource
  reason: string
  detail?: string
  context?: FaviconStatusContext
  updatedAt: number
}

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

export const FAVICON_VARIANT_LABELS: Record<FaviconVariant, string> = {
  default: 'Default',
  green: 'Green',
  blue: 'Blue',
  red: 'Red',
  theme: 'Theme',
  'theme-green': 'Theme + Green',
  'theme-blue': 'Theme + Blue',
  'theme-red': 'Theme + Red',
}

export const FAVICON_SOURCE_LABELS: Record<FaviconStatusSource, string> = {
  default: 'Default',
  'debug-menu': 'Debug menu',
  'build-notifications': 'Build notifications',
  'agent-conversation': 'Agent conversation',
  'legacy-theme': 'Legacy theme',
  'dynamic-favicon': 'Theme-aware host',
  unknown: 'Unknown',
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

const BLUE_VARIANTS = new Set<FaviconVariant>(['blue', 'theme-blue'])

let activeStatusVariant: FaviconVariant | null = null

let faviconStatus: FaviconStatus = {
  variant: 'default',
  source: 'default',
  reason: 'Idle (default favicon)',
  updatedAt: 0,
}

const faviconStatusListeners = new Set<(status: FaviconStatus) => void>()

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

function notifyFaviconStatusListeners(): void {
  for (const listener of faviconStatusListeners) {
    listener(faviconStatus)
  }
}

function recordFaviconStatus(
  variant: FaviconVariant,
  meta?: FaviconApplyMeta,
): void {
  if (meta?.source || meta?.reason || meta?.detail !== undefined || meta?.context) {
    faviconStatus = {
      variant,
      source: meta.source ?? 'unknown',
      reason: meta.reason ?? 'Favicon updated',
      detail: meta.detail,
      context: meta.context,
      updatedAt: Date.now(),
    }
  } else {
    // Silent re-apply (e.g. light/dark theme status PNG swap) keeps the
    // existing trigger explanation and only refreshes the active variant.
    faviconStatus = {
      ...faviconStatus,
      variant,
    }
  }
  notifyFaviconStatusListeners()
}

export function getFaviconStatus(): FaviconStatus {
  return faviconStatus
}

export function subscribeFaviconStatus(
  listener: (status: FaviconStatus) => void,
): () => void {
  faviconStatusListeners.add(listener)
  listener(faviconStatus)
  return () => {
    faviconStatusListeners.delete(listener)
  }
}

export function isBlueFaviconVariant(variant: FaviconVariant | null | undefined): boolean {
  return !!variant && BLUE_VARIANTS.has(variant)
}

export function isStatusFaviconVariant(
  variant: FaviconVariant | null | undefined,
): boolean {
  return !!variant && STATUS_VARIANTS.has(variant)
}

export function formatFaviconStatusSummary(status: FaviconStatus): string {
  const variantLabel = FAVICON_VARIANT_LABELS[status.variant] ?? status.variant
  if (status.source === 'default' && !isStatusFaviconVariant(status.variant)) {
    return variantLabel
  }
  return `${variantLabel} · ${status.reason}`
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
 * Update favicon `<link>` href/type in place.
 *
 * Important: do NOT remove React-managed head links (from route `head()` /
 * `<HeadContent />`). Yanking those nodes out of the DOM leaves React fibers
 * pointing at detached elements; the next navigation commit then crashes with
 * `Cannot read properties of null (reading 'removeChild')` (often a blank
 * screen right after sign-in). Cache-bust via query string instead of recreate.
 */
export function applyFaviconHref(
  href: string,
  options?: { type?: string; cacheBust?: boolean } & FaviconApplyMeta,
): void {
  if (typeof document === 'undefined') return

  const type = options?.type ?? faviconMimeType(href)
  const cacheBust = options?.cacheBust ?? true
  const resolvedHref =
    cacheBust && !href.includes('?') ? `${href}?v=${Date.now()}` : href

  const existing = document.querySelectorAll(
    "link[rel='icon'], link[rel='shortcut icon']",
  )

  if (existing.length > 0) {
    existing.forEach((node) => {
      if (!(node instanceof HTMLLinkElement)) return
      node.type = type
      node.href = resolvedHref
    })
  } else {
    const link = document.createElement('link')
    link.rel = 'icon'
    link.type = type
    link.href = resolvedHref
    document.head.appendChild(link)
  }

  // Only callers that pass status meta record a trigger. Prefer
  // {@link applyFaviconVariant} when the variant is known.
  if (
    options?.source ||
    options?.reason ||
    options?.detail !== undefined ||
    options?.context
  ) {
    let pathname = resolvedHref
    try {
      pathname = new URL(resolvedHref, window.location.origin).pathname
    } catch {
      // Keep raw href for variantFromPathname suffix matching.
    }
    recordFaviconStatus(
      options.variant ??
        variantFromPathname(pathname) ??
        faviconStatus.variant,
      options,
    )
  }
}

export function applyFaviconVariant(
  variant: FaviconVariant,
  options?: { cacheBust?: boolean } & FaviconApplyMeta,
): void {
  activeStatusVariant = STATUS_VARIANTS.has(variant) ? variant : null
  const { href, type } = resolveFaviconHref(variant)
  recordFaviconStatus(variant, options)
  applyFaviconHref(href, {
    type,
    cacheBust: options?.cacheBust,
  })
}

export function getDefaultFaviconVariant(): FaviconVariant {
  return usesThemeAwareFaviconHost() ? 'theme' : 'default'
}

if (typeof window !== 'undefined') {
  faviconStatus = {
    variant: getDefaultFaviconVariant(),
    source: 'default',
    reason: 'Idle (default favicon)',
    updatedAt: 0,
  }

  window
    .matchMedia('(prefers-color-scheme: dark)')
    .addEventListener('change', () => {
      if (activeStatusVariant && isThemeStatusVariant(activeStatusVariant)) {
        applyFaviconVariant(activeStatusVariant)
      }
    })
}
