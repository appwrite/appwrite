const STALE_CHUNK_RELOAD_KEY = 'console.staleChunkReloadAttempted'
/** Query param used to bust stale HTML/document caches on recovery reload. */
export const STALE_CHUNK_CACHE_BUST_PARAM = '_sc'

/** Default delay before clearing the one-reload guard after a successful boot. */
export const STALE_CHUNK_GUARD_CLEAR_DELAY_MS = 5_000

/**
 * Build-emitted client assets live under /assets/ with content hashes in the
 * filename. A failed load of one of these after a deploy is almost always a
 * stale shell referencing a deleted file, not an app bug.
 */
const HASHED_ASSET_PATH_RE = /\/assets\/[^?\s'"]+/i

/**
 * Last-resort message hints for browsers that omit the asset URL (e.g. some
 * MIME-type failures when the SPA shell is returned for a missing .js). Prefer
 * structural signals above this list.
 */
const STALE_CHUNK_MESSAGE_HINTS = [
  "'text/html' is not a valid javascript mime type",
  'mime type of "text/html"',
  'failed to fetch dynamically imported module',
  'error loading dynamically imported module',
  'importing a module script failed',
  'failed to load module script',
  'unable to preload css',
] as const

function errorMessage(error: unknown): string {
  if (error instanceof Error) return error.message
  if (typeof error === 'string') return error
  return ''
}

/** True when a URL/path points at a hashed build asset under /assets/. */
export function isHashedBuildAssetUrl(url: string): boolean {
  if (!url) return false
  try {
    const path = new URL(url, 'http://local.invalid').pathname
    return HASHED_ASSET_PATH_RE.test(path)
  } catch {
    return HASHED_ASSET_PATH_RE.test(url)
  }
}

/**
 * Detects failed dynamic import / route chunk loads without relying on
 * English error copy as the primary signal.
 *
 * Priority:
 * 1. Vite `vite:preloadError` (canonical for build-time preloads)
 * 2. Resource target / message containing a `/assets/...` URL
 * 3. Known MIME / module-load message hints (fallback)
 */
export function isStaleChunkLoadError(
  error: unknown,
  context?: { event?: Event; fromVitePreload?: boolean },
): boolean {
  if (context?.fromVitePreload) return true

  const target = context?.event?.target
  if (target instanceof HTMLScriptElement && isHashedBuildAssetUrl(target.src)) {
    return true
  }
  if (target instanceof HTMLLinkElement && isHashedBuildAssetUrl(target.href)) {
    return true
  }

  const message = errorMessage(error)
  if (message && HASHED_ASSET_PATH_RE.test(message)) return true

  const lower = message.toLowerCase()
  return STALE_CHUNK_MESSAGE_HINTS.some((hint) => lower.includes(hint))
}

/**
 * Inline boot script (runs via ScriptOnce before the app module graph).
 * Recovers when the entry/main chunk 404s after a deploy - before router.tsx
 * listeners exist - and uses a cache-busting navigation so soft reload cannot
 * reuse stale HTML that still points at deleted hashed assets.
 *
 * Prefer structural signals (vite:preloadError, /assets/ URLs) over message text.
 */
export const STALE_CHUNK_BOOT_SCRIPT = `(function(){
  var KEY=${JSON.stringify(STALE_CHUNK_RELOAD_KEY)};
  var PARAM=${JSON.stringify(STALE_CHUNK_CACHE_BUST_PARAM)};
  var ASSET_RE=${HASHED_ASSET_PATH_RE};
  var HINTS=${JSON.stringify([...STALE_CHUNK_MESSAGE_HINTS])};
  // Document already fetched with the bust param; scrub it before the router
  // parses search so route validateSearch never sees a transient key.
  try{
    var bootUrl=new URL(window.location.href);
    if(bootUrl.searchParams.has(PARAM)){
      bootUrl.searchParams.delete(PARAM);
      history.replaceState(history.state,"",bootUrl.pathname+bootUrl.search+bootUrl.hash);
    }
  }catch(e0){}
  function assetUrl(url){
    if(!url) return false;
    try{ return ASSET_RE.test(new URL(url,location.href).pathname); }
    catch(e){ return ASSET_RE.test(String(url)); }
  }
  function isStale(error, event, fromVite){
    if(fromVite) return true;
    var t=event&&event.target;
    if(t&&t.tagName==="SCRIPT"&&assetUrl(t.src)) return true;
    if(t&&t.tagName==="LINK"&&assetUrl(t.href)) return true;
    var msg=error&&error.message?error.message:(typeof error==="string"?error:"");
    if(msg&&ASSET_RE.test(msg)) return true;
    var lower=String(msg).toLowerCase();
    for(var i=0;i<HINTS.length;i++){ if(lower.indexOf(HINTS[i])!==-1) return true; }
    return false;
  }
  function tryReload(error, event, fromVite){
    if(!isStale(error, event, fromVite)) return false;
    try{
      if(sessionStorage.getItem(KEY)) return false;
      sessionStorage.setItem(KEY,String(Date.now()));
    }catch(e){}
    try{
      var url=new URL(window.location.href);
      url.searchParams.set(PARAM,String(Date.now()));
      window.location.replace(url.href);
    }catch(e2){
      window.location.reload();
    }
    return true;
  }
  window.addEventListener("unhandledrejection",function(event){
    if(tryReload(event&&event.reason, event, false)){
      if(event.preventDefault) event.preventDefault();
    }
  });
  window.addEventListener("error",function(event){
    var err=(event&&event.error)||(event&&event.message)||"";
    if(tryReload(err, event, false)){
      if(event.preventDefault) event.preventDefault();
    }
  }, true);
  // Canonical Vite signal for failed dynamic import / CSS preload in production.
  window.addEventListener("vite:preloadError",function(event){
    if(tryReload(event&&event.payload, event, true)){
      if(event.preventDefault) event.preventDefault();
    }
  });
})()`

/** Clears the one-time auto-reload guard (e.g. after a successful settle). */
export function clearStaleChunkReloadGuard(): void {
  if (typeof sessionStorage === 'undefined') return
  sessionStorage.removeItem(STALE_CHUNK_RELOAD_KEY)
}

/** Removes the temporary cache-bust query param after a successful recovery boot. */
export function stripStaleChunkCacheBustParam(): void {
  if (typeof window === 'undefined') return
  try {
    const url = new URL(window.location.href)
    if (!url.searchParams.has(STALE_CHUNK_CACHE_BUST_PARAM)) return
    url.searchParams.delete(STALE_CHUNK_CACHE_BUST_PARAM)
    const next = `${url.pathname}${url.search}${url.hash}`
    window.history.replaceState(window.history.state, '', next)
  } catch {
    // ignore
  }
}

/**
 * Clears the reload guard only after the app has stayed up long enough that
 * chunk loads likely succeeded. Must not run on router init immediately -
 * that defeats the one-reload limit and causes infinite reload loops when a
 * chunk is still missing after the first recovery attempt.
 */
export function scheduleClearStaleChunkReloadGuard(
  delayMs: number = STALE_CHUNK_GUARD_CLEAR_DELAY_MS,
): void {
  if (typeof window === 'undefined') return
  // Drop the cache-bust param as soon as the new document is running so the
  // address bar stays clean even if the user copies the URL mid-boot.
  stripStaleChunkCacheBustParam()
  window.setTimeout(() => {
    clearStaleChunkReloadGuard()
  }, delayMs)
}

/**
 * Force a full document navigation that bypasses cached HTML pointing at
 * deleted hashed assets. Soft `location.reload()` can re-serve stale HTML.
 */
export function forceReloadForStaleChunk(): void {
  if (typeof window === 'undefined') return
  try {
    const url = new URL(window.location.href)
    url.searchParams.set(STALE_CHUNK_CACHE_BUST_PARAM, String(Date.now()))
    window.location.replace(url.href)
  } catch {
    window.location.reload()
  }
}

/**
 * Reload once per recovery window when a stale JS chunk is detected (e.g. after deploy).
 * Returns true when a reload was triggered.
 */
export function tryReloadForStaleChunk(
  error: unknown,
  context?: { event?: Event; fromVitePreload?: boolean },
): boolean {
  if (typeof window === 'undefined') return false
  if (!isStaleChunkLoadError(error, context)) return false
  if (typeof sessionStorage !== 'undefined') {
    if (sessionStorage.getItem(STALE_CHUNK_RELOAD_KEY)) return false
    sessionStorage.setItem(STALE_CHUNK_RELOAD_KEY, String(Date.now()))
  }
  forceReloadForStaleChunk()
  return true
}
