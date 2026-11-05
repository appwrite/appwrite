import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { isMarketingPagePath } from '@/lib/marketing/is-marketing-page'

const CONSOLE_RIGHT_PANE_PREFIXES = [
  '/projects/',
  '/organizations/',
  '/account',
] as const

function matchesConsoleRightPanePath(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'

  if (normalized === '/') return false

  return CONSOLE_RIGHT_PANE_PREFIXES.some(
    (prefix) =>
      normalized === prefix.replace(/\/$/, '') || normalized.startsWith(prefix),
  )
}

/**
 * Console right pane (docs preview, assistant) is only available inside the
 * authenticated console. Marketing, docs, and other public pages keep it closed.
 */
export function isConsoleRightPanePath(pathname: string): boolean {
  if (isMarketingPagePath(pathname)) return false
  return matchesConsoleRightPanePath(pathname)
}

/**
 * Docs preview pane is only available inside the authenticated console
 * (project, organization, and account settings routes). Marketing and other
 * public pages should navigate to /docs instead.
 */
export function isConsoleDocsPreviewPath(pathname: string): boolean {
  if (!getActiveProfileFeatures().marketing) return false
  return matchesConsoleRightPanePath(pathname)
}
