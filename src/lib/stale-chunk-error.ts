const STALE_CHUNK_RELOAD_KEY = 'console.staleChunkReloadAttempted'
/** Query param used to bust stale HTML/document caches on recovery reload. */
export const STALE_CHUNK_CACHE_BUST_PARAM = '_sc'

/** Default delay before clearing the one-reload guard after a successful boot. */
export const STALE_CHUNK_GUARD_CLEAR_DELAY_MS = 5_000

/** Give the HTML revalidation request this long before falling back to reload. */
export const STALE_CHUNK_REVALIDATE_TIMEOUT_MS = 2_000

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
  if (
    typeof HTMLScriptElement !== 'undefined' &&
    target instanceof HTMLScriptElement &&
    isHashedBuildAssetUrl(target.src)
  ) {
    return true
  }
  if (
    typeof HTMLLinkElement !== 'undefined' &&
    target instanceof HTMLLinkElement &&
    isHashedBuildAssetUrl(target.href)
  ) {
    return true
  }

  const message = errorMessage(error)
  if (message && HASHED_ASSET_PATH_RE.test(message)) return true

  const lower = message.toLowerCase()
  return STALE_CHUNK_MESSAGE_HINTS.some((hint) => lower.includes(hint))
}

/**
 * Inline JS shared by the boot script. Revalidates the current HTML document
 * from the network (Cache-Control: no-cache), then reloads. A query-param-only
 * location.replace() is not enough: CDNs often ignore `?_sc=` and some browsers
 * no-op replace() after history.replaceState, which made the Reload CTA appear
 * to do nothing until the user hard-reloaded.
 */
const STALE_CHUNK_HARD_RELOAD_JS = `function documentUrl(){
    try{
      var u=new URL(window.location.href);
      u.searchParams.delete(${JSON.stringify(STALE_CHUNK_CACHE_BUST_PARAM)});
      return u.pathname+u.search;
    }catch(e){ return window.location.pathname||"/"; }
  }
  function reloadNow(){
    try{ window.location.reload(); }
    catch(e2){ window.location.href=documentUrl(); }
  }
  function hardReload(){
    var finished=false;
    var finish=function(){
      if(finished) return;
      finished=true;
      reloadNow();
    };
    try{
      var req=fetch(documentUrl(),{
        cache:"reload",
        credentials:"same-origin",
        headers:{"Cache-Control":"no-cache","Pragma":"no-cache"}
      });
      if(req&&typeof req.finally==="function") req.finally(finish);
      else finish();
    }catch(e3){ finish(); }
    setTimeout(finish,${String(STALE_CHUNK_REVALIDATE_TIMEOUT_MS)});
  }`

/**
 * Inline boot script (runs via ScriptOnce before the app module graph).
 * Recovers when the entry/main chunk 404s after a deploy - before router.tsx
 * listeners exist - and revalidates the HTML shell so cached documents that
 * still point at deleted hashed assets are not reused.
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
  ${STALE_CHUNK_HARD_RELOAD_JS}
  function tryReload(error, event, fromVite){
    if(!isStale(error, event, fromVite)) return false;
    try{
      if(sessionStorage.getItem(KEY)) return false;
      sessionStorage.setItem(KEY,String(Date.now()));
    }catch(e){}
    hardReload();
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

function getWindow(): Window | undefined {
  if (typeof globalThis !== 'object') return undefined
  const candidate = (globalThis as { window?: Window }).window
  if (candidate && typeof candidate.location !== 'undefined') return candidate
  return undefined
}

/** Removes the temporary cache-bust query param after a successful recovery boot. */
export function stripStaleChunkCacheBustParam(): void {
  const win = getWindow()
  if (!win) return
  try {
    const url = new URL(win.location.href)
    if (!url.searchParams.has(STALE_CHUNK_CACHE_BUST_PARAM)) return
    url.searchParams.delete(STALE_CHUNK_CACHE_BUST_PARAM)
    const next = `${url.pathname}${url.search}${url.hash}`
    win.history.replaceState(win.history.state, '', next)
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
  const win = getWindow()
  if (!win) return
  // Drop the cache-bust param as soon as the new document is running so the
  // address bar stays clean even if the user copies the URL mid-boot.
  stripStaleChunkCacheBustParam()
  win.setTimeout(() => {
    clearStaleChunkReloadGuard()
  }, delayMs)
}

/**
 * Current document URL with any leftover cache-bust param removed, so we
 * revalidate the real HTML shell instead of a unique `?_sc=` cache key that
 * CDNs may ignore or treat as a different entry.
 */
export function staleChunkDocumentUrl(
  href: string = getWindow()?.location.href ?? '',
): string {
  if (!href) return '/'
  try {
    const url = new URL(href, 'http://local.invalid')
    url.searchParams.delete(STALE_CHUNK_CACHE_BUST_PARAM)
    return `${url.pathname}${url.search}` || '/'
  } catch {
    return '/'
  }
}

function reloadCurrentDocument(): void {
  const win = getWindow()
  if (!win) return
  try {
    win.location.reload()
  } catch {
    win.location.href = staleChunkDocumentUrl()
  }
}

/**
 * Force a full document reload that bypasses cached HTML pointing at deleted
 * hashed assets.
 *
 * Soft `location.reload()` and query-param `location.replace(?_sc=)` are not
 * equivalent to a user hard reload: they can reuse cached HTML (CDN ignore
 * query string) or no-op after `history.replaceState`. Revalidate the document
 * with `cache: 'reload'` + Cache-Control: no-cache first (the same signal a
 * hard reload sends), then reload so the new shell's hashed assets load.
 */
export function forceReloadForStaleChunk(): void {
  const win = getWindow()
  if (!win) return

  const documentUrl = staleChunkDocumentUrl()
  let finished = false
  const reloadNow = () => {
    if (finished) return
    finished = true
    reloadCurrentDocument()
  }

  try {
    const revalidate = fetch(documentUrl, {
      cache: 'reload',
      credentials: 'same-origin',
      headers: {
        'Cache-Control': 'no-cache',
        Pragma: 'no-cache',
      },
    })
    void revalidate.finally(reloadNow)
  } catch {
    reloadNow()
    return
  }

  win.setTimeout(reloadNow, STALE_CHUNK_REVALIDATE_TIMEOUT_MS)
}

/**
 * Reload once per recovery window when a stale JS chunk is detected (e.g. after deploy).
 * Returns true when a reload was triggered.
 */
export function tryReloadForStaleChunk(
  error: unknown,
  context?: { event?: Event; fromVitePreload?: boolean },
): boolean {
  if (!getWindow()) return false
  if (!isStaleChunkLoadError(error, context)) return false
  if (typeof sessionStorage !== 'undefined') {
    if (sessionStorage.getItem(STALE_CHUNK_RELOAD_KEY)) return false
    sessionStorage.setItem(STALE_CHUNK_RELOAD_KEY, String(Date.now()))
  }
  forceReloadForStaleChunk()
  return true
}
