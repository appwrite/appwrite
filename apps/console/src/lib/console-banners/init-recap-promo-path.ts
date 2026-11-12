import { isMarketingPagePath } from '@/lib/marketing/is-marketing-page-path'

function isAuthPage(pathname: string): boolean {
  return (
    pathname === '/sign-in' ||
    pathname === '/sign-up' ||
    pathname === '/recovery' ||
    pathname === '/reset' ||
    pathname === '/join' ||
    pathname === '/mfa' ||
    pathname === '/verify-email' ||
    pathname === '/auth/magic-url'
  )
}

export function isFullscreenWizardPath(pathname: string): boolean {
  return pathname.includes('/create') || pathname.includes('/apps/add')
}

/**
 * Header promos (Product Hunt, Postgres) sit in ConsoleLayout chrome. The recap
 * card is a `fixed` sibling of the overflow-hidden app shell, so it paints
 * above that header. Hide recap while a header promo is visible.
 */
export function shouldHideInitRecapForHeaderPromo(
  _pathname: string,
  headerPromoVisible: boolean,
): boolean {
  return headerPromoVisible
}

function isInitPath(pathname: string): boolean {
  return pathname === '/init' || pathname.startsWith('/init/')
}

/** Console surfaces where the Init recap floating promo may appear. */
export function isInitRecapPromoPath(pathname: string): boolean {
  if (isInitPath(pathname)) return false
  if (isAuthPage(pathname)) return false
  if (isMarketingPagePath(pathname)) return false
  if (isFullscreenWizardPath(pathname)) return false

  const first = pathname.split('/').filter(Boolean)[0]
  return (
    first === 'projects' || first === 'organizations' || first === 'account'
  )
}
