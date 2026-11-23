import type { Tracking, TrackingEventOptions } from '@appwrite.io/console'
import { getRuntimeConfig } from '@/lib/runtime-config'

let tracking: Promise<Tracking | null> | null = null
let pendingPageview: TrackingEventOptions | null = null
let lastPageviewUrl: string | undefined

/**
 * The page without its query and fragment. Console links carry one-time
 * secrets there (invites, magic links, password resets, email verification),
 * and they must not leave the Console with an event.
 */
function getCleanPageUrl() {
  return `${window.location.origin}${window.location.pathname}`
}

function getCleanReferrer() {
  if (!document.referrer) return undefined
  try {
    const referrer = new URL(document.referrer)
    return `${referrer.origin}${referrer.pathname}`
  } catch {
    return undefined
  }
}

/**
 * Appwrite Analytics tracking for the Console's own traffic. Idempotent.
 *
 * Every event is sent with an explicit URL. The SDK's automatic collectors
 * (`start()`, outbound links, downloads, scroll depth, engagement time) read
 * `window.location.href`, query string included, so none of them are enabled.
 */
export function startAppwriteAnalytics() {
  if (typeof window === 'undefined' || tracking) return

  const {
    appwriteAnalyticsEndpoint: endpoint,
    appwriteAnalyticsProjectId: projectId,
    appwriteAnalyticsPropertyId: propertyId,
  } = getRuntimeConfig()
  if (!endpoint || !projectId || !propertyId) return

  const pending = import('@appwrite.io/console')
    .then(({ Analytics, Client, Tracking }) => {
      // Omitted credentials keep the Console session (cookies and the
      // localStorage cookieFallback) from being sent to the analytics project.
      const client = new Client()
        .setEndpoint(endpoint)
        .setProject(projectId)
        .setCredentials('omit')
      const tracker = new Tracking(new Analytics(client), propertyId)
      // The route's pageview can land before the SDK chunk loads
      if (pendingPageview) {
        tracker.track('pageview', pendingPageview)
        pendingPageview = null
      }
      return tracker
    })
    .catch((error) => {
      console.warn('Appwrite Analytics failed to load', error)
      // A chunk-load failure must not block a later retry.
      if (tracking === pending) tracking = null
      return null
    })
  tracking = pending
}

export function resetAppwriteAnalyticsForTests() {
  tracking = null
  pendingPageview = null
  lastPageviewUrl = undefined
}

/** Pageview for a route; held until tracking starts. */
export function trackAppwritePageview(options: TrackingEventOptions) {
  const url = options.url ?? getCleanPageUrl()
  // The first pageview takes the document's referrer; later ones the previous route
  const pageview = {
    ...options,
    url,
    referrer: lastPageviewUrl ?? getCleanReferrer(),
  }
  lastPageviewUrl = url

  if (!tracking) {
    pendingPageview = pageview
    return
  }
  void tracking.then((tracker) => tracker?.track('pageview', pageview))
}

/** Custom event; a no-op until tracking has started. */
export function trackAppwriteEvent(
  name: string,
  options: TrackingEventOptions,
) {
  if (!tracking) return
  const event = { ...options, url: options.url ?? getCleanPageUrl() }
  void tracking.then((tracker) => tracker?.track(name, event))
}
