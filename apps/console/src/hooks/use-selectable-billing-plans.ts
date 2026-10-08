import { useMemo } from 'react'
import {
  useVisitorCountryCode,
  useVisitorCountryResolutionComplete,
} from '@/hooks/use-visitor-country'
import { filterBillingPlansByLocation } from '@/lib/pricing/start-plan'
import { useBillingPlans } from '@/lib/react-query/hooks'

/**
 * Billing plans a visitor can choose, with country-gated plans (Start in IN/NP)
 * resolved the same way everywhere plans are sold. `currentPlanRef` keeps the
 * organization's current plan visible even outside its countries.
 */
export function useSelectableBillingPlans(currentPlanRef?: string | null) {
  const { plans: billingPlans, isLoading: billingPlansLoading } =
    useBillingPlans()
  const visitorCountryCode = useVisitorCountryCode()
  const visitorCountryReady = useVisitorCountryResolutionComplete()

  const selectablePlans = useMemo(
    () =>
      filterBillingPlansByLocation(
        billingPlans,
        visitorCountryCode,
        currentPlanRef,
      ),
    [billingPlans, visitorCountryCode, currentPlanRef],
  )

  return {
    billingPlans,
    selectablePlans,
    isLoading: billingPlansLoading || !visitorCountryReady,
  }
}
