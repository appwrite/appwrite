import { canTrackAnalytics } from '@/lib/cookie-consent/consent-state'
import { getRuntimeConfig } from '@/lib/runtime-config'

export const PLAUSIBLE_SCRIPT_SRC = getRuntimeConfig().plausibleScriptSrc

export const ANALYTICS_ENABLED = Boolean(PLAUSIBLE_SCRIPT_SRC)

function isAnalyticsAllowed() {
  return ANALYTICS_ENABLED && canTrackAnalytics()
}

export const PLAUSIBLE_INIT_SCRIPT = `window.plausible=window.plausible||function(){(plausible.q=plausible.q||[]).push(arguments)},plausible.init=plausible.init||function(i){plausible.o=i||{}};
plausible.init({ autoCapturePageviews: false })`

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

export function getAnalyticsRouteUrl(routePath: string) {
  if (typeof window === 'undefined') return routePath
  return `${window.location.origin}${routePath}`
}

export function trackPageView(routePath: string) {
  if (!isAnalyticsAllowed() || typeof window === 'undefined') return
  window.plausible?.('pageview', { url: getAnalyticsRouteUrl(routePath) })
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
