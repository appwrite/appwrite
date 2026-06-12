const CONSOLE_DOCS_PREVIEW_PREFIXES = [
  '/projects/',
  '/organizations/',
  '/account',
] as const

/**
 * Docs preview pane is only available inside the authenticated console
 * (project, organization, and account settings routes). Marketing and other
 * public pages should navigate to /docs instead.
 */
export function isConsoleDocsPreviewPath(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'

  if (normalized === '/') return false

  return CONSOLE_DOCS_PREVIEW_PREFIXES.some(
    (prefix) =>
      normalized === prefix.replace(/\/$/, '') || normalized.startsWith(prefix),
  )
}
