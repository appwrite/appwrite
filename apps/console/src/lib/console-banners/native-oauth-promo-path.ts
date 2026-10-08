import { isFullscreenWizardPath } from '@/lib/console-banners/init-recap-promo-path'

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

/** Native sign-in launch blog post (secondary promo CTA). */
export const NATIVE_OAUTH_PROMO_LEARN_MORE_PATH =
  '/blog/post/announcing-native-sign-in'

/** Native OAuth floating card: fixed 1:1 square. */
export const NATIVE_OAUTH_PROMO_CARD_SIZE_PX = 450

/** Project Auth OAuth provider settings (native sign-in lives here). */
export function isNativeOAuthSettingsPath(pathname: string): boolean {
  return /\/auth\/social-providers\/?$/.test(pathname)
}

/** Project routes where the Native OAuth floating promo may appear. */
export function isNativeOAuthPromoPath(pathname: string): boolean {
  if (isAuthPage(pathname)) return false
  if (isFullscreenWizardPath(pathname)) return false
  if (isNativeOAuthSettingsPath(pathname)) return false

  const parts = pathname.split('/').filter(Boolean)
  return parts[0] === 'projects' && parts.length >= 2
}

/**
 * Init recap and Native OAuth promos share the same bottom-left slot on project
 * routes. Prefer the project-scoped Native OAuth promo when both would show.
 */
export function shouldHideInitRecapForNativeOAuthPromo(
  pathname: string,
  nativeOAuthPromoVisible: boolean,
): boolean {
  if (!nativeOAuthPromoVisible) return false
  const parts = pathname.split('/').filter(Boolean)
  return parts[0] === 'projects'
}
