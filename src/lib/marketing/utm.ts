/**
 * Acquisition-source tracking for the marketing site shell, ported from
 * https://github.com/appwrite/website. `/` is a home-vs-console hop and is
 * not a marketing page, so it never POSTs.
 */

import { isConsoleRedirectHopPath } from '@/lib/root-guest-redirect'

const STORAGE_KEYS = {
  utmReferral: 'utmReferral',
  utmSource: 'utmSource',
  utmMedium: 'utmMedium',
  utmCampaign: 'utmCampaign',
  posted: 'utmSourcePosted',
} as const

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
 * Drop first-party referrers. The old site ignored anything containing
 * `//appwrite.io`. This site is the console and the marketing origin, so
 * same-origin referrers are ignored too.
 */
export function resolveExternalReferrer(
  documentReferrer: string,
  currentOrigin?: string,
): string | null {
  const referrer = documentReferrer.trim()
  if (!referrer) return null
  if (referrer.includes('//appwrite.io')) return null

  if (currentOrigin) {
    try {
      if (new URL(referrer).origin === currentOrigin) return null
    } catch {
      // Keep the raw referrer if it is not a valid URL.
    }
  }

  return referrer
}

export function parseAcquisitionSource(
  url: URL,
  documentReferrer: string,
  currentOrigin?: string,
): AcquisitionSource {
  const params = url.searchParams
  return {
    ref: firstQueryValue(params, 'ref'),
    referrer: resolveExternalReferrer(documentReferrer, currentOrigin),
    utmSource: firstQueryValue(params, 'utm_source'),
    utmMedium: firstQueryValue(params, 'utm_medium'),
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

export function persistAcquisitionSource(
  source: AcquisitionSource,
  storage: Pick<Storage, 'setItem'>,
): void {
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

function getSessionStorage(): Storage | undefined {
  if (typeof sessionStorage === 'undefined') return undefined
  return sessionStorage
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
    } catch {
      startedSignatures.delete(signature)
    }
  })()
}

/**
 * Capture landing-page source from the marketing site shell. `/` is a
 * home-vs-console hop and must not POST. Cloud only stores a row when at
 * least one of `ref`, an external referrer, or a UTM param is present.
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
  if (isConsoleRedirectHopPath(url.pathname)) return

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
