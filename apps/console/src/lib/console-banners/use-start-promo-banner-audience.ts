import { useQuery } from '@tanstack/react-query'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  useVisitorCountryCode,
  useVisitorCountryQueryState,
  useVisitorCountryResolutionComplete,
} from '@/hooks/use-visitor-country'
import { organizationsQueryOptions } from '@/lib/react-query/hooks/organizations'
import {
  isIndiaCountryCode,
  isNonPayingConsoleCustomer,
} from '@/lib/console-banners/start-promo-audience'

export type StartPromoBannerAudienceState =
  | { status: 'pending' }
  | { status: 'hidden' }
  | { status: 'eligible' }

/**
 * India + non-paying customers (signed-in) or India guests. Waits for locale,
 * account session, and (when signed in) organizations before resolving.
 */
export function useStartPromoBannerAudience(): StartPromoBannerAudienceState {
  const {
    isAuthenticated,
    isFetched: accountSettled,
    isPending: accountPending,
  } = useAuth()
  const countryCode = useVisitorCountryCode()
  const resolutionComplete = useVisitorCountryResolutionComplete()
  const localeQueries = useVisitorCountryQueryState()
  const isIndia = isIndiaCountryCode(countryCode)

  const orgQuery = useQuery({
    ...organizationsQueryOptions(),
    enabled: isAuthenticated && resolutionComplete && isIndia,
  })

  if (!resolutionComplete) {
    return { status: 'pending' }
  }

  if (localeQueries.localeFetching) {
    return { status: 'pending' }
  }

  if (!accountSettled || accountPending) {
    return { status: 'pending' }
  }

  if (!isIndia) {
    return { status: 'hidden' }
  }

  if (!isAuthenticated) {
    return { status: 'eligible' }
  }

  if (orgQuery.isLoading) {
    return { status: 'pending' }
  }

  const teams = orgQuery.data?.teams as
    | Array<{ billingPlan?: string; billingPlanId?: string }>
    | undefined

  return isNonPayingConsoleCustomer(teams)
    ? { status: 'eligible' }
    : { status: 'hidden' }
}
