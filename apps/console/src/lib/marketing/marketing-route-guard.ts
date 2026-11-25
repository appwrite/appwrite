import { redirect } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { MARKETING_PAGE_PATHS } from '@/lib/marketing/marketing-page-paths'
import { isRootRedirectPath } from '@/lib/root-guest-redirect'

/**
 * Marketing pages and docs that must be unreachable when the `marketing`
 * feature is off (e.g. self-hosted). `/` is excluded because its own loader
 * already routes to the console or sign-in.
 */
export function isMarketingGatedRouteId(routeId: string | undefined): boolean {
  if (!routeId || routeId === '/_marketing/') return false
  return (
    routeId === '/docs' ||
    routeId.startsWith('/docs/') ||
    routeId.startsWith('/_marketing/')
  )
}

/** `beforeLoad` guard for marketing and docs layout routes. */
export function redirectIfMarketingDisabled(pathname: string) {
  if (getActiveProfileFeatures().marketing) return
  if (isRootRedirectPath(pathname)) return
  throw redirect({ to: '/', replace: true })
}

/**
 * Runs before first paint. The server middleware covers env profiles and the
 * debug profile cookie; this covers a localStorage override whose cookie
 * mirror has not been written yet. Mirrors `matchesMarketingPagePath`.
 */
export const MARKETING_DISABLED_BOOT_SCRIPT = `(function(){
  try {
    var stored = JSON.parse(localStorage.getItem('debug:consoleProfile') || 'null');
    if (!stored || typeof stored !== 'object') return;
    var features = stored.features || {};
    if (stored.id !== 'self-hosted' && features.marketing !== false) return;
    var path = (location.pathname || '/').replace(/\\/+$/, '') || '/';
    if (path === '/') return;
    var prefixes = ['/docs', '/changelog', '/blog', '/threads'];
    var gated = path.indexOf('/products/') === 0 ||
      ${JSON.stringify(MARKETING_PAGE_PATHS)}.indexOf(path) !== -1;
    for (var i = 0; !gated && i < prefixes.length; i++) {
      if (path === prefixes[i] || path.indexOf(prefixes[i] + '/') === 0) gated = true;
    }
    if (gated) location.replace('/');
  } catch (e) {}
})()`
