import {
  isPricingPlanGridReady,
  shouldShowStartPlan,
} from '@/lib/pricing/visitor-country-resolution'
import {
  useVisitorCountryCode,
  useVisitorCountryResolutionComplete,
} from '@/hooks/use-visitor-country'

/**
 * Whether to show the Start plan. The pricing grid stays in the reserved shell
 * until country is known (SSR geo, cookie, debug mock, or locale.get()) so we
 * never flash the default 3-plan grid and then expand to 4.
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
