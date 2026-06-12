import { useSyncExternalStore } from 'react'
import {
  getEnvProfileFeatures,
  getInitOrgPromoBannerProfileFeatures,
  subscribeToProfileChange,
} from '@/lib/console-profiles'
import {
  loadDebugOverrides,
  subscribeToDebugOverrides,
} from '@/lib/debug-overrides'
import {
  getInitOrgPromoBannerContent,
  type InitOrgPromoBannerContent,
} from './org-promo-banner'

function resolveInitOrgPromoBannerContent(
  mockCurrentDay: number | null,
): InitOrgPromoBannerContent | null {
  if (!getInitOrgPromoBannerProfileFeatures().init) return null
  return getInitOrgPromoBannerContent({ mockCurrentDay })
}

function resolveInitOrgPromoBannerContentServer(): InitOrgPromoBannerContent | null {
  if (!getEnvProfileFeatures().init) return null
  return getInitOrgPromoBannerContent({ mockCurrentDay: null })
}

function subscribeInitOrgPromoBanner(onStoreChange: () => void) {
  const unsubscribeProfile = subscribeToProfileChange(onStoreChange)
  const unsubscribeDebug = subscribeToDebugOverrides(onStoreChange)
  return () => {
    unsubscribeProfile()
    unsubscribeDebug()
  }
}

/**
 * Init org promo banner content with a stable SSR/prerender snapshot.
 * Uses env-based profile (not endpoint detection) so marketing pages do not shift on hydration.
 */
export function useInitOrgPromoBannerContent(): InitOrgPromoBannerContent | null {
  return useSyncExternalStore(
    subscribeInitOrgPromoBanner,
    () =>
      resolveInitOrgPromoBannerContent(loadDebugOverrides().mockInitCurrentDay),
    resolveInitOrgPromoBannerContentServer,
  )
}
