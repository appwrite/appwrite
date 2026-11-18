import type { Tracking, TrackingEventOptions } from '@appwrite.io/console'
import { getRuntimeConfig } from '@/lib/runtime-config'

let tracking: Promise<Tracking | null> | null = null

/** Appwrite Analytics auto-tracking for the Console's own traffic. Idempotent. */
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
      tracker.start()
      // The Plausible site tracks file downloads too.
      tracker.enableAutoDownloadTracking()
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
}

/** Custom event; a no-op until tracking has started. */
export function trackAppwriteEvent(
  name: string,
  options: TrackingEventOptions,
) {
  void tracking?.then((tracker) => tracker?.track(name, options))
}
