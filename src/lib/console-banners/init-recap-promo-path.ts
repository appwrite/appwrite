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

function isFullscreenWizardPath(pathname: string): boolean {
  return pathname.includes('/create') || pathname.includes('/apps/add')
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
