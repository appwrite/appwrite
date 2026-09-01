import {
  getAnalyticsArea,
  getAnalyticsSurface,
} from '@/lib/analytics-route'
import { canTrackAnalytics } from '@/lib/cookie-consent/consent-state'
import { getActiveLanguage, type SupportedLanguage } from '@/lib/i18n/active-language'
import {
  PLAUSIBLE_PROXY_EVENT_PATH,
  PLAUSIBLE_PROXY_SCRIPT_PATH,
} from '@/lib/plausible-proxy'
import { getRuntimeConfig } from '@/lib/runtime-config'
import {
  getPlanNameFromTier,
  type CanonicalPlanId,
} from '@/lib/utils/plan-filter'

export {
  getAnalyticsArea,
  getAnalyticsSurface,
  type AnalyticsSurface,
} from '@/lib/analytics-route'

/** Upstream Plausible script URL (server proxy target). Not loaded in the browser. */
export const PLAUSIBLE_UPSTREAM_SCRIPT_SRC =
  getRuntimeConfig().plausibleScriptSrc

export const ANALYTICS_ENABLED = Boolean(PLAUSIBLE_UPSTREAM_SCRIPT_SRC)

/** First-party script path loaded in the browser (proxied; see plausible-proxy). */
export const PLAUSIBLE_SCRIPT_SRC = ANALYTICS_ENABLED
  ? PLAUSIBLE_PROXY_SCRIPT_PATH
  : ''

function isAnalyticsAllowed() {
  return ANALYTICS_ENABLED && canTrackAnalytics()
}

export const PLAUSIBLE_INIT_SCRIPT = `window.plausible=window.plausible||function(){(plausible.q=plausible.q||[]).push(arguments)},plausible.init=plausible.init||function(i){plausible.o=i||{}};
plausible.init({ autoCapturePageviews: false, endpoint: ${JSON.stringify(PLAUSIBLE_PROXY_EVENT_PATH)} })`

export type AnalyticsEventName =
  | 'Button Clicked'
  | 'Command Executed'
  | 'Control Changed'
  | 'Dialog Closed'
  | 'Dialog Opened'
  | 'Error Shown'
  | 'External Link Opened'
  | 'Filter Applied'
  | 'Form Submitted'
  | 'Form Validation Failed'
  | 'Menu Item Clicked'
  | 'Navigation Clicked'
  | 'Pagination Changed'
  | 'Resource Created'
  | 'Resource Creation Failed'
  | 'Search Performed'
  | 'Sort Changed'
  | 'Tab Changed'
  | 'View Mode Changed'
  | 'Wizard Opened'
  | 'Wizard Option Selected'

export type AnalyticsPropValue = string | number | boolean | null | undefined
export type AnalyticsProps = Record<string, AnalyticsPropValue>

/** Login state for Plausible custom properties. */
export type AnalyticsAuth = 'user' | 'guest'

/**
 * Billing plan bucket for Plausible custom properties.
 * Uses canonical console plan ids; guests (and unknown org) use `none`.
 */
export type AnalyticsPlan = CanonicalPlanId | 'none'

export type AnalyticsSessionProps = {
  auth: AnalyticsAuth
  plan: AnalyticsPlan
  lang: SupportedLanguage
}

type PlausibleOptions = {
  url?: string
  u?: string
  props?: Record<string, string | number | boolean>
}

declare global {
  interface Window {
    plausible?: (
      eventName: 'pageview' | string,
      options?: PlausibleOptions,
    ) => void
  }
}

const DEFAULT_SESSION_PROPS: AnalyticsSessionProps = {
  auth: 'guest',
  plan: 'none',
  lang: 'en',
}

let sessionProps: AnalyticsSessionProps = { ...DEFAULT_SESSION_PROPS }

/**
 * Custom events must not create Plausible visits before the first pageview.
 * Waiting on auth/plan for pageviews while clicks/dialogs fire immediately
 * produced visitors with 0 pageviews (views/visit < 1, ~1s duration).
 */
let hasTrackedPageview = false
type PendingAnalyticsEvent = {
  eventName: string
  options?: PlausibleOptions
}
const pendingAnalyticsEvents: PendingAnalyticsEvent[] = []

/**
 * Sync auth/plan/lang from React (account + active org plan + i18n).
 * Call during render so pageviews/events in the same commit see fresh values.
 */
export function setAnalyticsSessionProps(
  next: Partial<AnalyticsSessionProps>,
) {
  sessionProps = {
    ...sessionProps,
    ...next,
  }
}

export function getAnalyticsSessionProps(): AnalyticsSessionProps {
  return {
    ...sessionProps,
    // Prefer live language in case it changed outside the sync component.
    lang: getActiveLanguage(),
  }
}

export function getAnalyticsPlanFromBillingId(
  planId: string | null | undefined,
): AnalyticsPlan {
  if (!planId) return 'none'
  return getPlanNameFromTier(planId)
}

function normalizeAnalyticsProps(props: AnalyticsProps = {}) {
  return Object.fromEntries(
    Object.entries(props).filter(
      ([, value]) => value !== null && value !== undefined,
    ),
  ) as Record<string, string | number | boolean>
}

/**
 * Route path used for analytics. Public pages (marketing, docs, blog, ...)
 * report the full concrete pathname so per-page traffic is visible.
 * Console, account, and auth routes report the sanitized route template
 * (e.g. /projects/$projectId/databases/$databaseId) so raw IDs never leave.
 */
export function getAnalyticsRoutePath(
  routeId: string | undefined,
  pathname: string,
) {
  const template = routeId
    ?.split('/')
    .filter(Boolean)
    .filter((part) => !part.startsWith('_'))
    .join('/')

  const routePath = template ? `/${template}` : pathname || '/'
  const surface = getAnalyticsSurface(routePath)
  if (surface === 'marketing' || surface === 'docs') {
    return pathname || routePath
  }

  return routePath
}

export function getAnalyticsRouteUrl(routePath: string) {
  if (typeof window === 'undefined') return routePath
  return `${window.location.origin}${routePath}`
}

function getGlobalAnalyticsProps(routePath?: string): AnalyticsProps {
  const session = getAnalyticsSessionProps()
  return {
    auth: session.auth,
    plan: session.plan,
    lang: session.lang,
    ...(routePath ? { surface: getAnalyticsSurface(routePath) } : {}),
  }
}

function flushPendingAnalyticsEvents() {
  if (typeof window === 'undefined' || !window.plausible) return
  while (pendingAnalyticsEvents.length > 0) {
    const pending = pendingAnalyticsEvents.shift()
    if (!pending) break
    window.plausible(pending.eventName, pending.options)
  }
}

const MAX_PENDING_ANALYTICS_EVENTS = 20

export function trackPageView(routePath: string) {
  if (!isAnalyticsAllowed() || typeof window === 'undefined') return
  // Plausible's init stub queues until the script loads; without it, skip so we
  // do not mark the session as pageviewed and drop later real pageviews.
  if (!window.plausible) return

  window.plausible('pageview', {
    url: getAnalyticsRouteUrl(routePath),
    props: normalizeAnalyticsProps({
      route: routePath,
      area: getAnalyticsArea(routePath),
      ...getGlobalAnalyticsProps(routePath),
    }),
  })
  hasTrackedPageview = true
  flushPendingAnalyticsEvents()
}

export function trackEvent(
  eventName: AnalyticsEventName | string,
  props: AnalyticsProps = {},
  options: { routePath?: string; url?: string } = {},
) {
  if (!isAnalyticsAllowed() || typeof window === 'undefined') return

  const routePath = options.routePath
  const plausibleOptions: PlausibleOptions = {
    url:
      options.url ?? (routePath ? getAnalyticsRouteUrl(routePath) : undefined),
    props: normalizeAnalyticsProps({
      ...(routePath
        ? { route: routePath, area: getAnalyticsArea(routePath) }
        : {}),
      ...props,
      // Session dimensions win so callers cannot accidentally override them.
      ...getGlobalAnalyticsProps(routePath),
    }),
  }

  if (!hasTrackedPageview) {
    if (pendingAnalyticsEvents.length < MAX_PENDING_ANALYTICS_EVENTS) {
      pendingAnalyticsEvents.push({ eventName, options: plausibleOptions })
    }
    return
  }

  window.plausible?.(eventName, plausibleOptions)
}

export function getSafeInternalPathParts(pathname: string) {
  const parts = pathname.split('/').filter(Boolean)
  if (parts[0] === 'projects') {
    return { scope: 'project', area: parts[2] ?? 'overview' }
  }
  if (parts[0] === 'organizations') {
    return { scope: 'organization', area: parts[2] ?? 'overview' }
  }
  return { scope: parts[0] ?? 'root', area: parts[0] ?? 'root' }
}
