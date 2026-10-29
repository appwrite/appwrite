import {
  isPricingPlanGridReady,
  shouldShowStartPlan,
} from '@/lib/pricing/visitor-country-resolution'
import {
  useVisitorCountryCode,
  useVisitorCountryResolutionComplete,
} from '@/hooks/use-visitor-country'

/**
 * Whether to show the Start plan. The pricing grid stays empty until locale is
 * known on the client (after hydration) so we never paint 3 plans then swap to 4.
 */
export function useStartPlanVisibility(): {
  ready: boolean
  showStartPlan: boolean
} {
  const countryCode = useVisitorCountryCode()
  const resolutionComplete = useVisitorCountryResolutionComplete()
  const ready = isPricingPlanGridReady(countryCode, resolutionComplete)

  return {
    ready,
    showStartPlan: shouldShowStartPlan(ready, countryCode),
  }
}
