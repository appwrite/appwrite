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
 * Coarse product surface for Plausible custom properties.
 * Derived from the sanitized route template, not the raw pathname.
 */
export type AnalyticsSurface =
  | 'marketing'
  | 'console'
  | 'docs'
  | 'account'
  | 'auth'

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

export function getAnalyticsRoutePath(
  routeId: string | undefined,
  pathname: string,
) {
  const routePath = routeId
    ?.split('/')
    .filter(Boolean)
    .filter((part) => !part.startsWith('_'))
    .join('/')

  return routePath ? `/${routePath}` : pathname || '/'
}

export function getAnalyticsArea(routePath: string) {
  const parts = routePath.split('/').filter(Boolean)
  if (parts[0] === 'projects') return parts[2] ?? 'overview'
  if (parts[0] === 'organizations') return parts[2] ?? 'overview'
  return parts[0] ?? 'root'
}

/**
 * Map a sanitized route template to a coarse Plausible `surface` property.
 */
export function getAnalyticsSurface(routePath: string): AnalyticsSurface {
  const root = routePath.split('/').filter(Boolean)[0] ?? ''

  if (root === 'docs') return 'docs'
  if (root === 'account') return 'account'

  if (
    root === 'sign-in' ||
    root === 'sign-up' ||
    root === 'join' ||
    root === 'verify-email' ||
    root === 'auth' ||
    root === 'oauth2' ||
    root === 'reset' ||
    root === 'card'
  ) {
    return 'auth'
  }

  if (
    root === 'projects' ||
    root === 'organizations' ||
    root === 'upgrade' ||
    root === 'generator'
  ) {
    return 'console'
  }

  return 'marketing'
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

export function trackPageView(routePath: string) {
  if (!isAnalyticsAllowed() || typeof window === 'undefined') return
  window.plausible?.('pageview', {
    url: getAnalyticsRouteUrl(routePath),
    props: normalizeAnalyticsProps({
      route: routePath,
      area: getAnalyticsArea(routePath),
      ...getGlobalAnalyticsProps(routePath),
    }),
  })
}

export function trackEvent(
  eventName: AnalyticsEventName | string,
  props: AnalyticsProps = {},
  options: { routePath?: string; url?: string } = {},
) {
  if (!isAnalyticsAllowed() || typeof window === 'undefined') return

  const routePath = options.routePath
  window.plausible?.(eventName, {
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
  })
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
