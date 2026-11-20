import { AppwriteException } from '@appwrite.io/console'

/**
 * An organization that requires single sign-on refuses a member's requests until
 * they sign in through its identity provider (`sso_required`). The Console sends
 * them to the SSO sign-in, which brings them back to the page they were on.
 */
export function isSSORequiredError(error: unknown): boolean {
  return error instanceof AppwriteException && error.type === 'sso_required'
}

export function redirectToRequiredSSO(): void {
  if (typeof window === 'undefined' || window.location.pathname === '/sso') {
    return
  }
  const back = `${window.location.pathname}${window.location.search}`
  const target = new URL('/sso', window.location.origin)
  target.searchParams.set('required', 'true')
  target.searchParams.set('redirect', back)
  window.location.assign(target.toString())
}
