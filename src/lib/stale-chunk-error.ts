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
 * Absolute or root-relative hashed asset URL embedded in an error message.
 * Used to confirm a real missing chunk (404 / HTML MIME) before auto-reload.
 */
const HASHED_ASSET_URL_IN_MESSAGE_RE =
  /(?:https?:\/\/[^'"\s]+)?(\/assets\/[^'"\s?]+)/i

/**
 * Confirmed deploy skew: the server returned the SPA HTML shell for a JS/CSS
 * module request (missing hashed file fell through to index.html historically,
 * or a misconfigured CDN).
 *
 * Do NOT match generic dynamic-import failures ("Importing a module script
 * failed", "Failed to fetch dynamically imported module"). Firefox/Safari
 * emit those when an in-flight import is cancelled during navigation/preload,
 * which is not a stale deploy.
 */
const STALE_CHUNK_MIME_HTML_HINTS = [
  "'text/html' is not a valid javascript mime type",
  'mime type of "text/html"',
] as const

function errorMessage(error: unknown): string {
  if (error instanceof Error) return error.message
  if (typeof error === 'string') return error
  return ''
}

function messageIndicatesHtmlMimeForScript(message: string): boolean {
  const lower = message.toLowerCase()
  return STALE_CHUNK_MIME_HTML_HINTS.some((hint) => lower.includes(hint))
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
 * Best-effort hashed asset URL from a resource error target or error message.
 * Returns an absolute or root-relative URL suitable for a same-origin fetch.
 */
export function extractHashedAssetUrl(
  error: unknown,
  event?: Event,
): string | null {
  const target = event?.target
  if (
    typeof HTMLScriptElement !== 'undefined' &&
    target instanceof HTMLScriptElement &&
    isHashedBuildAssetUrl(target.src)
  ) {
    return target.src
  }
  if (
    typeof HTMLLinkElement !== 'undefined' &&
    target instanceof HTMLLinkElement &&
    isHashedBuildAssetUrl(target.href)
  ) {
    return target.href
  }

  const message = errorMessage(error)
  if (!message) return null
  const match = message.match(HASHED_ASSET_URL_IN_MESSAGE_RE)
  if (!match?.[1]) return null
  // Prefer the full absolute URL when the message included a host.
  const absolute = message.match(
    /https?:\/\/[^'"\s]+\/assets\/[^'"\s?]+/i,
  )
  return absolute?.[0] ?? match[1]
}

/**
 * TanStack `lazyRouteComponent` loads `importer().then(mod => mod[exportName])`
 * with `exportName` typically `'component'`. After a deploy, a 404'd chunk can
 * fulfill as `undefined` instead of rejecting, which throws:
 * `Cannot read properties of undefined (reading 'component')`.
 * That message has no hashed `/assets/` URL, so MIME/URL heuristics miss it.
 */
export function isLazyRouteComponentLoadError(error: unknown): boolean {
  const message = errorMessage(error)
  const isUndefinedComponentRead =
    /Cannot read propert(?:y|ies) of undefined \(reading ['"]component['"]\)/i.test(
      message,
    ) ||
    /can't access property ['"]component['"] of (?:undefined|null)/i.test(
      message,
    ) ||
    /undefined is not an object \(evaluating ['"][^'"]*component['"]\)/i.test(
      message,
    )
  if (!isUndefinedComponentRead) return false

  const stack = error instanceof Error ? (error.stack ?? '') : ''
  if (!stack) return true
  return /lazyRouteComponent/i.test(stack)
}

/**
 * Sync classification for UI / Sentry: confirmed MIME mismatch, a failure
 * that names a hashed `/assets/` URL (likely deploy skew), or TanStack's
 * follow-on TypeError when a lazy route module is missing. Does not treat
 * bare "module script failed" / "failed to fetch dynamically imported module"
 * messages as stale — those are common Firefox cancel/abort noise.
 *
 * Auto-reload is stricter: see {@link tryReloadForStaleChunk} (verifies 404 /
 * HTML MIME before reloading on asset-URL candidates).
 */
export function isStaleChunkLoadError(
  error: unknown,
  context?: { event?: Event; fromVitePreload?: boolean },
): boolean {
  if (isLazyRouteComponentLoadError(error)) return true

  const message = errorMessage(error)
  if (messageIndicatesHtmlMimeForScript(message)) return true

  if (extractHashedAssetUrl(error, context?.event)) return true

  // vite:preloadError alone is not confirmation (cancelled intent preloads
  // also fire it). Only treat as stale when the payload names an asset or MIME.
  if (context?.fromVitePreload) {
    return (
      messageIndicatesHtmlMimeForScript(message) ||
      extractHashedAssetUrl(error, context.event) !== null
    )
  }

  return false
}

/**
 * Confirm the asset is actually missing or returned as HTML (stale shell).
 * Network failures and successful JS/CSS responses are not stale deploys.
 */
export async function confirmStaleHashedAsset(
  assetUrl: string,
): Promise<boolean> {
  if (!assetUrl || !isHashedBuildAssetUrl(assetUrl)) return false
  try {
    const response = await fetch(assetUrl, {
      method: 'GET',
      cache: 'no-store',
      credentials: 'same-origin',
      // Avoid executing the module; we only need status / Content-Type.
      headers: { Accept: '*/*' },
    })
    if (response.status === 404) return true
    const contentType = (response.headers.get('content-type') ?? '').toLowerCase()
    if (contentType.includes('text/html')) return true
    return false
  } catch {
    // Offline / aborted / CORS — do not hard-reload.
    return false
  }
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
 * Only auto-reloads on confirmed signals: HTML MIME for a script, or a hashed
 * /assets/ URL that fetches as 404 / text/html. Bare dynamic-import cancel
 * messages (common on Firefox) do not reload.
 */
export const STALE_CHUNK_BOOT_SCRIPT = `(function(){
  var KEY=${JSON.stringify(STALE_CHUNK_RELOAD_KEY)};
  var PARAM=${JSON.stringify(STALE_CHUNK_CACHE_BUST_PARAM)};
  var ASSET_RE=${HASHED_ASSET_PATH_RE};
  var ASSET_IN_MSG=${HASHED_ASSET_URL_IN_MESSAGE_RE};
  var MIME_HINTS=${JSON.stringify([...STALE_CHUNK_MIME_HTML_HINTS])};
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
  function msgText(error){
    return error&&error.message?error.message:(typeof error==="string"?error:"");
  }
  function isMimeHtml(msg){
    var lower=String(msg).toLowerCase();
    for(var i=0;i<MIME_HINTS.length;i++){ if(lower.indexOf(MIME_HINTS[i])!==-1) return true; }
    return false;
  }
  function extractAsset(error, event){
    var t=event&&event.target;
    if(t&&t.tagName==="SCRIPT"&&assetUrl(t.src)) return t.src;
    if(t&&t.tagName==="LINK"&&assetUrl(t.href)) return t.href;
    var msg=msgText(error);
    if(!msg) return null;
    var abs=msg.match(/https?:\\/\\/[^'"\\s]+\\/assets\\/[^'"\\s?]+/i);
    if(abs&&abs[0]) return abs[0];
    var m=msg.match(ASSET_IN_MSG);
    return m&&m[1]?m[1]:null;
  }
  function beginGuardedReload(){
    try{
      if(sessionStorage.getItem(KEY)) return false;
      sessionStorage.setItem(KEY,String(Date.now()));
    }catch(e){}
    hardReload();
    return true;
  }
  ${STALE_CHUNK_HARD_RELOAD_JS}
  function confirmAssetThenReload(url){
    try{
      fetch(url,{method:"GET",cache:"no-store",credentials:"same-origin",headers:{Accept:"*/*"}})
        .then(function(res){
          var ct=((res.headers&&res.headers.get("content-type"))||"").toLowerCase();
          if(res.status===404||ct.indexOf("text/html")!==-1) beginGuardedReload();
        })
        .catch(function(){});
    }catch(e){}
  }
  function tryReload(error, event){
    var msg=msgText(error);
    if(isMimeHtml(msg)) return beginGuardedReload();
    var url=extractAsset(error, event);
    if(!url) return false;
    confirmAssetThenReload(url);
    // Swallow while we verify — avoids unhandledrejection noise for cancels
    // that still name an /assets/ URL; reload only if the asset is gone.
    return true;
  }
  window.addEventListener("unhandledrejection",function(event){
    if(tryReload(event&&event.reason, event)){
      if(event.preventDefault) event.preventDefault();
    }
  });
  window.addEventListener("error",function(event){
    var err=(event&&event.error)||(event&&event.message)||"";
    if(tryReload(err, event)){
      if(event.preventDefault) event.preventDefault();
    }
  }, true);
  window.addEventListener("vite:preloadError",function(event){
    if(tryReload(event&&event.payload, event)){
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

function beginGuardedReload(): boolean {
  if (typeof sessionStorage !== 'undefined') {
    if (sessionStorage.getItem(STALE_CHUNK_RELOAD_KEY)) return false
    sessionStorage.setItem(STALE_CHUNK_RELOAD_KEY, String(Date.now()))
  }
  forceReloadForStaleChunk()
  return true
}

/**
 * Reload once per recovery window when a confirmed stale JS chunk is detected.
 *
 * Confirmed = HTML MIME for a module request, a hashed `/assets/` URL that
 * re-fetches as 404 / text/html, or TanStack lazyRouteComponent's undefined
 * `component` export after a failed chunk load. Returns true when the error
 * was claimed (reload started, or async verification started) so callers can
 * preventDefault.
 */
export function tryReloadForStaleChunk(
  error: unknown,
  context?: { event?: Event; fromVitePreload?: boolean },
): boolean {
  if (!getWindow()) return false

  const message = errorMessage(error)
  if (
    messageIndicatesHtmlMimeForScript(message) ||
    isLazyRouteComponentLoadError(error)
  ) {
    return beginGuardedReload()
  }

  const assetUrl = extractHashedAssetUrl(error, context?.event)
  if (!assetUrl) {
    // fromVitePreload without asset URL / MIME: cancelled preload, not deploy.
    return false
  }

  void confirmStaleHashedAsset(assetUrl).then((stale) => {
    if (!stale) return
    beginGuardedReload()
  })
  // Claim the error while verifying so cancel noise with an /assets/ URL does
  // not spam Sentry; reload only if confirmation succeeds.
  return true
}
