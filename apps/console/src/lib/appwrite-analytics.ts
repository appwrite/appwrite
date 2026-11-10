import type { Tracking } from '@appwrite.io/console'
import { getRuntimeConfig } from '@/lib/runtime-config'

let tracking: Promise<Tracking> | null = null

/** Appwrite Analytics auto-tracking for the Console's own traffic. Idempotent. */
export function startAppwriteAnalytics() {
  if (typeof window === 'undefined' || tracking) return

  const {
    appwriteAnalyticsEndpoint: endpoint,
    appwriteAnalyticsProjectId: projectId,
    appwriteAnalyticsPropertyId: propertyId,
  } = getRuntimeConfig()
  if (!endpoint || !projectId || !propertyId) return

  tracking = import('@appwrite.io/console').then(
    ({ Analytics, Client, Tracking }) => {
      // Omitted credentials keep the Console session (cookies and the
      // localStorage cookieFallback) from being sent to the analytics project.
      const client = new Client()
        .setEndpoint(endpoint)
        .setProject(projectId)
        .setCredentials('omit')
      const tracker = new Tracking(new Analytics(client), propertyId)
      tracker.start()
      return tracker
    },
  )
}

export function stopAppwriteAnalytics() {
  const current = tracking
  tracking = null
  void current?.then((tracker) => {
    tracker.disableAutoPageviews()
    tracker.disableAutoOutboundTracking()
    tracker.disableAutoScrollDepth()
    tracker.disableAutoEngagementTime()
  })
}
