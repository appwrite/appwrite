import { useQuery } from '@tanstack/react-query'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  useVisitorCountryCode,
  useVisitorCountryQueryState,
  useVisitorCountryResolutionComplete,
} from '@/hooks/use-visitor-country'
import { organizationsQueryOptions } from '@/lib/react-query/hooks/organizations'
import {
  isNonPayingConsoleCustomer,
  isStartPromoBannerCountry,
} from '@/lib/console-banners/start-promo-audience'

export type StartPromoBannerAudienceState =
  | { status: 'pending' }
  | { status: 'hidden' }
  | { status: 'eligible' }

/**
 * Start-eligible country + non-paying customers (signed-in) or eligible guests.
 * Waits for locale, account session, and (when signed in) organizations before resolving.
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
  const inEligibleCountry = isStartPromoBannerCountry(countryCode)

  const orgQuery = useQuery({
    ...organizationsQueryOptions(),
    enabled: isAuthenticated && resolutionComplete && inEligibleCountry,
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

  if (!inEligibleCountry) {
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
