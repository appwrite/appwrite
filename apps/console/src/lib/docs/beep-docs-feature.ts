import { getActiveProfileFeatures } from '@/lib/console-profiles'

const BEEP_DOCS_PATH = '/docs/tooling/beep'

export function isBeepDocsSlug(slug: string): boolean {
  return slug === 'tooling/beep' || slug.startsWith('tooling/beep/')
}

export function isBeepDocsPathname(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  return (
    normalized === BEEP_DOCS_PATH || normalized.startsWith(`${BEEP_DOCS_PATH}/`)
  )
}

export function isBeepDocsHref(href: string): boolean {
  const path = href.split(/[?#]/, 2)[0] ?? href
  return path === BEEP_DOCS_PATH || path.startsWith(`${BEEP_DOCS_PATH}/`)
}

/** Beep docs follow the `alerts` flag, since Beep delivers the alerts that monitors raise. */
export function isBeepDocsEnabled(): boolean {
  return getActiveProfileFeatures().alerts
}

/**
 * Whether Beep docs should be treated as unavailable for route guards.
 *
 * The `alerts` flag defaults to off and is turned on through a debug profile
 * override, which lives in localStorage and isn't visible to SSR. On the
 * server, do not block so a reload doesn't bounce to /docs before the client
 * can apply the override. The docs sidebar still enforces the real flag.
 */
export function shouldBlockBeepDocs(): boolean {
  if (isBeepDocsEnabled()) return false
  if (typeof window === 'undefined') return false
  return true
}
