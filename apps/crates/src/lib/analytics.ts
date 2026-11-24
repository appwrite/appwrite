import { Analytics, Client, Tracking } from 'appwrite'

const ENDPOINT = 'https://fra.cloud.appwrite.io/v1'
const PROJECT_ID = 'utopia'
const TRACKING_ID = '6ad00d04001941a324da'

let client: Client | undefined
let tracking: Tracking | undefined

/** Shared Appwrite client for browser-side analytics. */
export function getAppwriteClient() {
  if (typeof window === 'undefined') {
    throw new Error('Appwrite client is only available in the browser')
  }
  if (!client) {
    client = new Client().setEndpoint(ENDPOINT).setProject(PROJECT_ID)
  }
  return client
}

/** Start automatic analytics once per session (respects Do Not Track by default). */
export function initAnalyticsTracking() {
  if (typeof window === 'undefined' || tracking) return tracking
  tracking = new Tracking(new Analytics(getAppwriteClient()), TRACKING_ID)
  tracking.start()
  tracking.enableAutoDownloadTracking()
  return tracking
}

export function getAnalyticsTracking() {
  if (typeof window === 'undefined') return undefined
  return tracking ?? initAnalyticsTracking()
}

type EventProps = Record<string, string | number | boolean>

export function trackDocsEvent(name: string, props?: EventProps) {
  getAnalyticsTracking()?.track(name, props ? { props } : undefined)
}

export function trackDocsPageview() {
  getAnalyticsTracking()?.pageview()
}
