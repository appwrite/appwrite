import { useEffect, useState } from 'react'
import { persistVisitorCountryCode } from '@/lib/locale/visitor-country'
import { RUNTIME_CONFIG_WINDOW_KEY } from '@/lib/runtime-config-shared'
import { normalizeCountryCode } from '@/lib/pricing/start-plan'
import { LONG_STALE_TIME } from '@/lib/react-query/hooks/constants'
import type { QueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'

const LOCALE_PREFETCH_WINDOW_KEY = '__LOCALE_PREFETCH__'
const LOCALE_PREFETCH_RESULT_KEY = '__LOCALE_PREFETCH_RESULT__'

type LocalePrefetch = Promise<unknown>

declare global {
  interface Window {
    [LOCALE_PREFETCH_WINDOW_KEY]?: LocalePrefetch
    [LOCALE_PREFETCH_RESULT_KEY]?: unknown
  }
}

let resolvedPrefetch: Models.Locale | null | undefined

export function isPrefetchedLocale(
  value: unknown,
): value is Models.Locale {
  return (
    !!value &&
    typeof value === 'object' &&
    'countryCode' in value &&
    typeof (value as { countryCode?: unknown }).countryCode === 'string'
  )
}

/**
 * Starts locale.get() as soon as HTML parses, in parallel with the app bundle.
 * Must run after the runtime-config ScriptOnce so the endpoint is available.
 */
export function getLocalePrefetchScript(): string {
  return `(function(){try{var c=window.${RUNTIME_CONFIG_WINDOW_KEY}||{};var e=String(c.appwriteEndpoint||'').replace(/\\/$/,'');if(!e)return;window.${LOCALE_PREFETCH_WINDOW_KEY}=fetch(e+'/locale',{credentials:'include',headers:{'X-Appwrite-Project':'console'}}).then(function(r){return r.ok?r.json():null}).then(function(j){window.${LOCALE_PREFETCH_RESULT_KEY}=j;return j}).catch(function(){return null});}catch(e){}})();`
}

export function readPrefetchedLocaleSync(): Models.Locale | null {
  if (resolvedPrefetch) return resolvedPrefetch
  if (typeof window === 'undefined') return null
  const value = window[LOCALE_PREFETCH_RESULT_KEY]
  if (!isPrefetchedLocale(value)) return null
  resolvedPrefetch = value
  return value
}

export async function readPrefetchedLocale(): Promise<Models.Locale | null> {
  const sync = readPrefetchedLocaleSync()
  if (sync) return sync
  if (typeof window === 'undefined') return null

  const pending = window[LOCALE_PREFETCH_WINDOW_KEY]
  if (!pending) return null

  try {
    const value = await pending
    if (!isPrefetchedLocale(value)) return null
    resolvedPrefetch = value
    return value
  } catch {
    return null
  }
}

/**
 * HTML-shell locale, applied only after mount. Reading it during render would
 * mismatch SSR (empty grid) and freeze that HTML until a parent like account.get
 * re-renders the tree.
 */
export function usePrefetchedLocale(): Models.Locale | null {
  const [locale, setLocale] = useState<Models.Locale | null>(null)

  useEffect(() => {
    const synced = readPrefetchedLocaleSync()
    if (synced) {
      setLocale(synced)
      return
    }
    const pending = window[LOCALE_PREFETCH_WINDOW_KEY]
    if (!pending) return
    let cancelled = false
    void pending.then((value) => {
      if (cancelled || !isPrefetchedLocale(value)) return
      resolvedPrefetch = value
      setLocale(value)
    })
    return () => {
      cancelled = true
    }
  }, [])

  return locale
}

export function seedLocaleQueryFromHtmlPrefetch(queryClient: QueryClient): void {
  if (typeof window === 'undefined') return
  void readPrefetchedLocale().then((locale) => {
    if (!locale) return
    persistVisitorCountryCode(locale.countryCode)
    queryClient.setQueryDefaults(['locale', 'console'], {
      staleTime: LONG_STALE_TIME,
      gcTime: LONG_STALE_TIME,
    })
    queryClient.setQueryData(['locale', 'console'], locale)
  })
}

export function prefetchedLocaleCountryCode(
  locale: Models.Locale | null | undefined,
): string | null {
  return normalizeCountryCode(locale?.countryCode)
}
