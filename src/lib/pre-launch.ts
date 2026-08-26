import { getRuntimeConfig } from '@/lib/runtime-config'

/** localStorage key (also mirrored to a cookie for SSR). */
export const PRE_LAUNCH_DEBUG_STORAGE_KEY = 'debug:preLaunch'

export const PRE_LAUNCH_COOKIE_NAME = 'debug_pre_launch'

const PRE_LAUNCH_COOKIE_MAX_AGE_SECONDS = 60 * 60 * 24 * 365

/**
 * Pre-launch lock: only Init (landing, ticket share pages, OG images) and
 * sign-in are reachable. Unset or unrecognized `VITE_CONSOLE_PRE_LAUNCH`
 * → enabled. `false` / `0` / `disabled` turns it off.
 */
export function isPreLaunchEnabledFromEnv(
  envValue: string | null | undefined,
): boolean {
  const normalized = (envValue ?? '').toLowerCase().trim()
  if (normalized === 'false' || normalized === '0' || normalized === 'disabled')
    return false
  return true
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
  if (normalized === '/og/init.png') return true
  // Debug previews (pink menu) stay reachable while the rest of the site is locked.
  if (normalized === '/debug' || normalized.startsWith('/debug/')) return true
  return AUTH_ALLOWED_PATHS.has(normalized)
}

function parseBooleanFlag(raw: string | null | undefined): boolean | null {
  if (raw == null) return null
  const normalized = raw.toLowerCase().trim()
  if (normalized === 'true' || normalized === '1') return true
  if (normalized === 'false' || normalized === '0') return false
  return null
}

function readStorageOverride(): boolean | null {
  if (typeof window === 'undefined' || !window.localStorage) return null
  try {
    return parseBooleanFlag(
      localStorage.getItem(PRE_LAUNCH_DEBUG_STORAGE_KEY),
    )
  } catch {
    return null
  }
}

const COOKIE_PATTERN = new RegExp(
  `(?:^|;\\s*)${PRE_LAUNCH_COOKIE_NAME}=([^;]*)`,
)

function readCookieOverride(
  cookieHeader?: string | null,
): boolean | null {
  const source =
    cookieHeader ??
    (typeof document !== 'undefined' ? document.cookie : '')
  if (!source) return null
  const match = source.match(COOKIE_PATTERN)
  if (!match?.[1]) return null
  try {
    return parseBooleanFlag(decodeURIComponent(match[1]))
  } catch {
    return parseBooleanFlag(match[1])
  }
}

export function syncPreLaunchCookie(value: boolean | null): void {
  if (typeof document === 'undefined') return
  const secure = window.location.protocol === 'https:' ? '; Secure' : ''
  if (value === null) {
    document.cookie = `${PRE_LAUNCH_COOKIE_NAME}=; path=/; max-age=0; SameSite=Lax${secure}`
    return
  }
  document.cookie = `${PRE_LAUNCH_COOKIE_NAME}=${value ? 'true' : 'false'}; path=/; max-age=${PRE_LAUNCH_COOKIE_MAX_AGE_SECONDS}; SameSite=Lax${secure}`
}

/**
 * Debug localStorage (client) or cookie (SSR) override wins over env.
 * Default (no override) is on.
 */
export function isPreLaunchModeEnabled(
  cookieHeader?: string | null,
): boolean {
  const stored = readStorageOverride()
  if (stored !== null) return stored
  const cookie = readCookieOverride(cookieHeader)
  if (cookie !== null) return cookie
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
 * does not flash. Debug localStorage/cookie override is honored.
 */
export const PRE_LAUNCH_BOOT_SCRIPT = `(function(){
  try {
    var cfg = window.__APP_CONFIG__ || {};
    var flag = String(cfg.preLaunch || '').toLowerCase().trim();
    var enabled = !(flag === 'false' || flag === '0' || flag === 'disabled');
    var cookieRe = new RegExp('(?:^|;\\\\s*)${PRE_LAUNCH_COOKIE_NAME}=([^;]*)');
    var cookieMatch = document.cookie.match(cookieRe);
    if (cookieMatch && cookieMatch[1]) {
      var cookieVal = decodeURIComponent(cookieMatch[1]).toLowerCase().trim();
      if (cookieVal === 'false' || cookieVal === '0') enabled = false;
      else if (cookieVal === 'true' || cookieVal === '1') enabled = true;
    }
    try {
      var ls = localStorage.getItem('${PRE_LAUNCH_DEBUG_STORAGE_KEY}');
      if (ls === 'true') enabled = true;
      else if (ls === 'false') enabled = false;
    } catch (e1) {}
    if (!enabled) return;
    var path = (location.pathname || '/').replace(/\\/+$/, '') || '/';
    if (path === '/init' || path.indexOf('/init/') === 0) return;
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
