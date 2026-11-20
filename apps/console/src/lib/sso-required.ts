import { AppwriteException } from '@appwrite.io/console'

/**
 * An organization that requires single sign-on refuses a member's requests until
 * they sign in through its identity provider (`sso_required`). The Console sends
 * them to the SSO sign-in, which brings them back to the page they were on.
 */
export function isSSORequiredError(error: unknown): boolean {
  return error instanceof AppwriteException && error.type === 'sso_required'
}

/** `/sso` for an organization that refused, returning to `returnPath` after. */
export function requiredSSOHref(returnPath: string): string {
  const params = new URLSearchParams({ required: 'true', redirect: returnPath })
  return `/sso?${params.toString()}`
}

/**
 * Send the person to the SSO sign-in. `returnPath` is where to come back to,
 * by default the page they are on.
 */
export function redirectToRequiredSSO(returnPath?: string): void {
  if (typeof window === 'undefined' || window.location.pathname === '/sso') {
    return
  }
  const back =
    returnPath ?? `${window.location.pathname}${window.location.search}`
  window.location.assign(requiredSSOHref(back))
}

/** The organization or project a Console path belongs to, if any. */
export function pageResourceId(pathname: string): string | undefined {
  const prefix = ['/organizations/', '/projects/'].find((start) =>
    pathname.startsWith(start),
  )
  if (!prefix) return undefined
  let id = pathname.slice(prefix.length)
  for (const end of ['/', '?', '#']) id = id.split(end)[0]
  return id || undefined
}

function keyMentions(value: unknown, id: string): boolean {
  if (value === id) return true
  if (Array.isArray(value)) return value.some((item) => keyMentions(item, id))
  if (value && typeof value === 'object') {
    return Object.values(value).some((item) => keyMentions(item, id))
  }
  return false
}

/**
 * Whether a refused request is about the page the person is on: a query whose
 * key names the organization or project in the path, or any mutation made on
 * such a page. Requests about other organizations (the Console probing which
 * one to open, the organization switcher) are left to their caller, so a
 * member is never sent to sign in to an organization they did not open.
 */
export function refusalConcernsPage(
  pathname: string,
  queryKey?: readonly unknown[],
): boolean {
  const id = pageResourceId(pathname)
  if (!id) return false
  return queryKey === undefined || keyMentions(queryKey, id)
}
