import { getRuntimeConfig } from '@/lib/runtime-config'

const LEGACY_PRE_LAUNCH_DEBUG_STORAGE_KEY = 'debug:preLaunch'
const LEGACY_PRE_LAUNCH_COOKIE_NAME = 'debug_pre_launch'

/**
 * Pre-launch lock: only Init (landing, ticket share pages, OG images) and
 * sign-in are reachable. Unset or unrecognized `VITE_CONSOLE_PRE_LAUNCH`
 * → disabled. `true` / `1` / `enabled` turns it on.
 */
export function isPreLaunchEnabledFromEnv(
  envValue: string | null | undefined,
): boolean {
  const normalized = (envValue ?? '').toLowerCase().trim()
  if (normalized === 'true' || normalized === '1' || normalized === 'enabled')
    return true
  return false
}

export function getPreLaunchDefault(): boolean {
  return isPreLaunchEnabledFromEnv(getRuntimeConfig().preLaunch)
}

const AUTH_ALLOWED_PATHS = new Set([
  '/sign-in',
  '/sign-up',
  '/sign-out',
  '/recovery',
  '/reset',
  '/mfa',
  '/verify-email',
  '/auth/magic-url',
  '/auth/oauth2/success',
  '/auth/oauth2/failure',
])

export function isPreLaunchAllowedPath(
  pathname: string | null | undefined,
): boolean {
  const normalized = (pathname ?? '/').replace(/\/+$/, '') || '/'
  if (normalized === '/init' || normalized.startsWith('/init/')) return true
  if (normalized === '/setup.md') return true
  if (normalized === '/discord') return true
  if (normalized === '/og/init.png') return true
  // Debug previews (pink menu) stay reachable while the rest of the site is locked.
  if (normalized === '/debug' || normalized.startsWith('/debug/')) return true
  return AUTH_ALLOWED_PATHS.has(normalized)
}

/**
 * Heavy marketing/content routes that should not run while the site is locked to
 * Init. Bots and RSS consumers still hit these even when document navigations
 * redirect to `/init`, which can OOM small containers.
 */
export function isPreLaunchHeavyContentPath(
  pathname: string | null | undefined,
): boolean {
  const normalized = (pathname ?? '/').replace(/\/+$/, '') || '/'
  if (normalized === '/health') return false
  if (normalized.startsWith('/assets/')) return false
  if (normalized.startsWith('/api/init/')) return false
  if (normalized.startsWith('/init/') && normalized.endsWith('/og.png')) {
    return false
  }

  const heavyPrefixes = [
    '/blog',
    '/changelog',
    '/docs',
    '/integrations',
    '/threads',
    '/home',
    '/products',
    '/pricing',
    '/company',
    '/og/image.png',
    '/og/image',
  ]
  if (heavyPrefixes.some((prefix) => normalized === prefix || normalized.startsWith(`${prefix}/`))) {
    return true
  }

  const heavyExact = new Set([
    '/blog.md',
    '/changelog.md',
    '/integrations.md',
    '/docs.md',
    '/llms.txt',
    '/llms-full.txt',
    '/for-agents',
    '/for-agents.md',
    '/blog/rss.xml',
    '/changelog/rss.xml',
    '/robots.txt',
  ])
  if (heavyExact.has(normalized)) return true
  if (normalized === '/sitemap.xml' || normalized.startsWith('/sitemap/')) {
    return true
  }
  if (normalized.startsWith('/docs/llms')) return true
  if (normalized.startsWith('/.well-known/')) return true

  return false
}

/** Remove legacy debug-menu pre-launch override (localStorage + cookie). */
export function clearLegacyPreLaunchDebugOverride(): void {
  if (typeof window !== 'undefined' && window.localStorage) {
    try {
      window.localStorage.removeItem(LEGACY_PRE_LAUNCH_DEBUG_STORAGE_KEY)
    } catch {
      // ignore
    }
  }
  if (typeof document === 'undefined') return
  const secure = window.location.protocol === 'https:' ? '; Secure' : ''
  document.cookie = `${LEGACY_PRE_LAUNCH_COOKIE_NAME}=; path=/; max-age=0; SameSite=Lax${secure}`
}

/** Whether pre-launch lock is active (env only: `VITE_CONSOLE_PRE_LAUNCH`). */
export function isPreLaunchModeEnabled(
  _cookieHeader?: string | null,
): boolean {
  return getPreLaunchDefault()
}

export function isPreLaunchDocumentRequest(request: Request): boolean {
  const dest = request.headers.get('sec-fetch-dest')
  if (dest === 'document' || dest === 'iframe') return true
  if (dest && dest !== 'empty') return false
  const accept = request.headers.get('accept') ?? ''
  return accept.includes('text/html')
}

/**
 * Runs before first paint. Sends locked routes to `/init` so prerendered HTML
 * does not flash.
 */
export const PRE_LAUNCH_BOOT_SCRIPT = `(function(){
  try {
    var cfg = window.__APP_CONFIG__ || {};
    var flag = String(cfg.preLaunch || '').toLowerCase().trim();
    var enabled = flag === 'true' || flag === '1' || flag === 'enabled';
    if (!enabled) return;
    var path = (location.pathname || '/').replace(/\\/+$/, '') || '/';
    if (path === '/init' || path.indexOf('/init/') === 0) return;
    if (path === '/discord') return;
    if (path === '/og/init.png') return;
    if (path === '/debug' || path.indexOf('/debug/') === 0) return;
    var auth = {
      '/sign-in': 1, '/sign-up': 1, '/sign-out': 1, '/recovery': 1, '/reset': 1,
      '/mfa': 1, '/verify-email': 1, '/auth/magic-url': 1,
      '/auth/oauth2/success': 1, '/auth/oauth2/failure': 1
    };
    if (auth[path]) return;
    location.replace('/init');
  } catch (e) {}
})()`
