/**
 * Acquisition-source tracking for guest visits, ported from
 * https://github.com/appwrite/website.
 */

import {
  isConsoleRedirectHopPath,
  isRootRedirectPath,
} from '@/lib/root-guest-redirect'

const STORAGE_KEYS = {
  utmReferral: 'utmReferral',
  utmSource: 'utmSource',
  utmMedium: 'utmMedium',
  utmCampaign: 'utmCampaign',
  posted: 'utmSourcePosted',
  sourceIds: 'utmSourceIds',
} as const

const SOURCE_IDS_LIMIT = 10

// Cloud has no click-id column, so a paid click without utm_source would be
// stored as organic search.
const CLICK_ID_SOURCES = [
  ['gclid', 'google'],
  ['msclkid', 'bing'],
  ['fbclid', 'facebook'],
] as const

/** Query keys Plausible uses for campaign / click-id attribution. */
export const ATTRIBUTION_QUERY_PARAM_NAMES = [
  'utm_source',
  'utm_medium',
  'utm_campaign',
  'utm_term',
  'utm_content',
  'ref',
  'gclid',
  'fbclid',
  'msclkid',
] as const

export type AcquisitionSource = {
  ref: string | null
  referrer: string | null
  utmSource: string | null
  utmMedium: string | null
  utmCampaign: string | null
}

function firstQueryValue(params: URLSearchParams, key: string): string | null {
  const value = params.get(key)?.trim()
  return value ? value : null
}

/**
 * Drop first-party referrers: appwrite.io and its subdomains (marketing, blog,
 * regional consoles), and the current origin on any other host.
 */
export function resolveExternalReferrer(
  documentReferrer: string,
  currentOrigin?: string,
): string | null {
  const referrer = documentReferrer.trim()
  if (!referrer) return null

  let url: URL
  try {
    url = new URL(referrer)
  } catch {
    // Keep the raw referrer if it is not a valid URL.
    return referrer
  }

  if (url.hostname === 'appwrite.io' || url.hostname.endsWith('.appwrite.io')) {
    return null
  }
  if (currentOrigin && url.origin === currentOrigin) return null

  return referrer
}

export function parseAcquisitionSource(
  url: URL,
  documentReferrer: string,
  currentOrigin?: string,
): AcquisitionSource {
  const params = url.searchParams
  const utmSource = firstQueryValue(params, 'utm_source')
  const utmMedium = firstQueryValue(params, 'utm_medium')
  const clickSource = utmSource
    ? undefined
    : CLICK_ID_SOURCES.find(([key]) => firstQueryValue(params, key))?.[1]
  return {
    ref: firstQueryValue(params, 'ref'),
    referrer: resolveExternalReferrer(documentReferrer, currentOrigin),
    utmSource: utmSource ?? clickSource ?? null,
    utmMedium: clickSource ? (utmMedium ?? 'cpc') : utmMedium,
    utmCampaign: firstQueryValue(params, 'utm_campaign'),
  }
}

export function hasAcquisitionSource(source: AcquisitionSource): boolean {
  return Boolean(
    source.ref ||
      source.referrer ||
      source.utmSource ||
      source.utmMedium ||
      source.utmCampaign,
  )
}

/**
 * A landing carrying a value we store (utm source/medium/campaign, ref or a
 * click id) replaces the stored tuple so campaigns never mix keys.
 */
export function persistAcquisitionSource(
  source: AcquisitionSource,
  storage: Pick<Storage, 'setItem' | 'removeItem'>,
): void {
  if (
    source.ref ||
    source.utmSource ||
    source.utmMedium ||
    source.utmCampaign
  ) {
    storage.removeItem(STORAGE_KEYS.utmSource)
    storage.removeItem(STORAGE_KEYS.utmMedium)
    storage.removeItem(STORAGE_KEYS.utmCampaign)
    storage.removeItem(STORAGE_KEYS.utmReferral)
  }
  if (source.utmSource)
    storage.setItem(STORAGE_KEYS.utmSource, source.utmSource)
  if (source.utmMedium)
    storage.setItem(STORAGE_KEYS.utmMedium, source.utmMedium)
  if (source.utmCampaign) {
    storage.setItem(STORAGE_KEYS.utmCampaign, source.utmCampaign)
  }
  if (source.ref || source.referrer) {
    storage.setItem(
      STORAGE_KEYS.utmReferral,
      source.referrer || source.ref || '',
    )
  }
}

function looksLikeHttpUrl(value: string): boolean {
  try {
    const parsed = new URL(value)
    return parsed.protocol === 'http:' || parsed.protocol === 'https:'
  } catch {
    return false
  }
}

/** Rebuild a source after `/` document redirects drop the landing query. */
export function acquisitionSourceFromStorage(
  storage: Pick<Storage, 'getItem'>,
): AcquisitionSource {
  const referral = storage.getItem(STORAGE_KEYS.utmReferral)
  const referralIsUrl = !!referral && looksLikeHttpUrl(referral)
  return {
    ref: referral && !referralIsUrl ? referral : null,
    referrer: referralIsUrl ? referral : null,
    utmSource: storage.getItem(STORAGE_KEYS.utmSource),
    utmMedium: storage.getItem(STORAGE_KEYS.utmMedium),
    utmCampaign: storage.getItem(STORAGE_KEYS.utmCampaign),
  }
}

/** Values the growth API historically received from the website. */
export function getReferrerAndUtmSource(
  storage: Pick<Storage, 'getItem'> | undefined = getSessionStorage(),
): Record<string, string> {
  if (!storage) return {}

  return (['utmReferral', 'utmSource', 'utmMedium'] as const).reduce(
    (acc, key) => {
      const value = storage.getItem(STORAGE_KEYS[key])
      if (value) acc[key] = value
      return acc
    },
    {} as Record<string, string>,
  )
}

export function getUtmSourceForLink(
  storage: Pick<Storage, 'getItem'> | undefined = getSessionStorage(),
): string {
  if (!storage) return ''

  const params = new URLSearchParams()
  ;(['utmSource', 'utmMedium', 'utmCampaign'] as const).forEach((key) => {
    const value = storage.getItem(STORAGE_KEYS[key])
    if (value) params.set(key.replace('utm', 'utm_').toLowerCase(), value)
  })

  return params.toString()
}

export function pickAttributionSearchParams(
  params: URLSearchParams,
): URLSearchParams {
  const picked = new URLSearchParams()
  for (const key of ATTRIBUTION_QUERY_PARAM_NAMES) {
    const value = params.get(key)?.trim()
    if (value) picked.set(key, value)
  }
  return picked
}

/**
 * Attribution query string for Plausible pageviews on marketing/docs.
 * Uses the current URL first, then session-stored UTMs so SPA navigations
 * keep campaign tags after the address bar drops them.
 */
export function getAttributionSearchForPlausible(
  urlSearch: string,
  storage: Pick<Storage, 'getItem'> | undefined = getSessionStorage(),
): string {
  const merged = pickAttributionSearchParams(new URLSearchParams(urlSearch))

  if (storage) {
    const storedUtms = new URLSearchParams(getUtmSourceForLink(storage))
    for (const [key, value] of storedUtms) {
      if (!merged.has(key)) merged.set(key, value)
    }

    const referral = storage.getItem(STORAGE_KEYS.utmReferral)
    if (referral && !looksLikeHttpUrl(referral) && !merged.has('ref')) {
      merged.set('ref', referral)
    }
  }

  const serialized = merged.toString()
  return serialized ? `?${serialized}` : ''
}

function getSessionStorage(): Storage | undefined {
  if (typeof sessionStorage === 'undefined') return undefined
  return sessionStorage
}

function getLocalStorage(): Storage | undefined {
  try {
    return typeof localStorage === 'undefined' ? undefined : localStorage
  } catch {
    return undefined
  }
}

/** Source ids created while a guest, waiting to be linked to an account. */
export function readPendingSourceIds(
  storage: Pick<Storage, 'getItem'> | undefined = getLocalStorage(),
): string[] {
  try {
    const parsed: unknown = JSON.parse(
      storage?.getItem(STORAGE_KEYS.sourceIds) ?? '[]',
    )
    return Array.isArray(parsed)
      ? parsed.filter((id): id is string => typeof id === 'string' && !!id)
      : []
  } catch {
    return []
  }
}

export function addPendingSourceId(
  id: string,
  storage: Pick<Storage, 'getItem' | 'setItem'> | undefined = getLocalStorage(),
): void {
  const ids = [...readPendingSourceIds(storage).filter((x) => x !== id), id]
  try {
    storage?.setItem(
      STORAGE_KEYS.sourceIds,
      JSON.stringify(ids.slice(-SOURCE_IDS_LIMIT)),
    )
  } catch {
    // Storage full or blocked; the backend fingerprint fallback still applies.
  }
}

function compactSourcePayload(
  source: AcquisitionSource,
): Record<string, string> {
  const payload: Record<string, string> = {}
  if (source.ref) payload.ref = source.ref
  if (source.referrer) payload.referrer = source.referrer
  if (source.utmSource) payload.utmSource = source.utmSource
  if (source.utmCampaign) payload.utmCampaign = source.utmCampaign
  if (source.utmMedium) payload.utmMedium = source.utmMedium
  return payload
}

const startedSignatures = new Set<string>()

function createSource(source: AcquisitionSource): void {
  const payload = compactSourcePayload(source)
  const signature = JSON.stringify(payload)
  if (!signature || signature === '{}') return

  const storage = getSessionStorage()
  if (
    startedSignatures.has(signature) ||
    storage?.getItem(STORAGE_KEYS.posted) === signature
  ) {
    return
  }
  // Lock before any await. React Strict Mode and search-string churn would
  // otherwise start several POSTs before the first one finishes.
  startedSignatures.add(signature)

  void (async () => {
    try {
      const { isCloudProfile } = await import('@/lib/console-profiles')
      if (!isCloudProfile()) {
        startedSignatures.delete(signature)
        return
      }

      const { sdk } = await import('@/lib/appwrite/sdk')
      const client = sdk.forConsole.client
      const endpoint = client.config.endpoint.replace(/\/$/, '')
      const project = client.config.project || 'console'
      // Cloud marks this route `origin: *` with credentials disabled, so the
      // POST goes out without cookies; attribution is by IP/UA fingerprint.
      // This stays a raw fetch because the SDK can't set `keepalive`, which
      // lets the POST finish when the visitor leaves the landing page.
      const response = await fetch(`${endpoint}/console/sources`, {
        method: 'POST',
        credentials: 'omit',
        keepalive: true,
        headers: {
          'content-type': 'application/json',
          'X-Appwrite-Project': project,
        },
        body: JSON.stringify(payload),
      })
      if (!response.ok) {
        throw new Error(`createSource failed: ${response.status}`)
      }
      storage?.setItem(STORAGE_KEYS.posted, signature)
      const created = (await response.json().catch(() => null)) as {
        $id?: unknown
      } | null
      if (typeof created?.$id === 'string') addPendingSourceId(created.$id)
    } catch {
      startedSignatures.delete(signature)
    }
  })()
}

let linkingSources = false

/**
 * Attach the sources this browser created as a guest to the signed-in account.
 * Best effort: a failure keeps the ids for the next authenticated load.
 */
export function linkPendingSources(): void {
  if (typeof window === 'undefined' || linkingSources) return
  const sourceIds = readPendingSourceIds()
  if (sourceIds.length === 0) return
  linkingSources = true

  void (async () => {
    try {
      const { isCloudProfile } = await import('@/lib/console-profiles')
      if (!isCloudProfile()) return

      const { sdk } = await import('@/lib/appwrite/sdk')
      const client = sdk.forConsole.client
      await client.call(
        'patch',
        new URL(`${client.config.endpoint.replace(/\/$/, '')}/console/sources`),
        { 'content-type': 'application/json' },
        { sourceIds },
      )
      // Drop only the sent ids; a POST may have added more while this ran.
      const storage = getLocalStorage()
      const remaining = readPendingSourceIds(storage).filter(
        (id) => !sourceIds.includes(id),
      )
      try {
        storage?.setItem(STORAGE_KEYS.sourceIds, JSON.stringify(remaining))
      } catch {
        // Blocked storage; the ids are re-sent next time.
      }
    } catch {
      // Never block sign-up; the ids stay for the next attempt.
    } finally {
      linkingSources = false
    }
  })()
}

/**
 * Capture the landing source for a guest visit. `/` only counts
 * when it renders the guest homepage; as a console hop it must not POST.
 * Cloud only stores a row when at least one of `ref`, an external referrer,
 * or a UTM param is present.
 */
export function saveReferrerAndUtmSource(
  url: URL = typeof window === 'undefined'
    ? new URL('http://localhost')
    : new URL(window.location.href),
  documentReferrer: string = typeof document === 'undefined'
    ? ''
    : document.referrer,
  currentOrigin: string | undefined = typeof window === 'undefined'
    ? undefined
    : window.location.origin,
): void {
  if (typeof window === 'undefined') return
  if (isConsoleRedirectHopPath(url.pathname) && !isRootRedirectPath(url.pathname)) {
    return
  }

  const source = parseAcquisitionSource(url, documentReferrer, currentOrigin)
  const storage = getSessionStorage()
  if (storage) persistAcquisitionSource(source, storage)

  const toPost =
    hasAcquisitionSource(source) || !storage
      ? source
      : acquisitionSourceFromStorage(storage)

  if (!hasAcquisitionSource(toPost)) return

  createSource(toPost)
}
