/**
 * Synchronous consent gate for cookie-based diagnostics (Sentry) outside React.
 * Updated by CookieConsentProvider when locale and stored prefs are resolved.
 * Plausible is cookieless and is not gated here.
 */

let consentResolved = false
let bannerRequired = false
let analyticsGranted = false
const consentListeners = new Set<() => void>()

function notifyConsentListeners() {
  for (const listener of consentListeners) {
    listener()
  }
}

export function subscribeCookieConsent(listener: () => void) {
  consentListeners.add(listener)
  return () => {
    consentListeners.delete(listener)
  }
}

export function resetCookieConsentStateForTests() {
  consentResolved = false
  bannerRequired = false
  analyticsGranted = false
}

export function setCookieConsentState(args: {
  resolved: boolean
  bannerRequired: boolean
  analyticsGranted: boolean
}) {
  consentResolved = args.resolved
  bannerRequired = args.bannerRequired
  analyticsGranted = args.analyticsGranted
  notifyConsentListeners()
}

/** True when cookie-gated diagnostics (Sentry) may run. Not used for Plausible. */
export function canTrackAnalytics(): boolean {
  if (!consentResolved) return false
  if (!bannerRequired) return true
  return analyticsGranted
}

export function isCookieConsentResolved(): boolean {
  return consentResolved
}

export function isCookieConsentBannerRequired(): boolean {
  return bannerRequired
}
