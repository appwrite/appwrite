import { isStartPlanEligibleCountry } from '@/lib/pricing/start-plan'
import {
  useVisitorCountryCode,
  useVisitorCountryQuerySettled,
} from '@/hooks/use-visitor-country'

/**
 * Whether to show the Start plan. `ready` is true once a country signal exists
 * (SSR geo, cookie, debug mock, or locale.get()) so pricing never paints the
 * default 3-plan grid and then expands to 4.
 */
export function useStartPlanVisibility(): {
  ready: boolean
  showStartPlan: boolean
} {
  const countryCode = useVisitorCountryCode()
  const querySettled = useVisitorCountryQuerySettled()
  const ready = countryCode !== null || querySettled
  return {
    ready,
    showStartPlan: ready && isStartPlanEligibleCountry(countryCode),
  }
}
