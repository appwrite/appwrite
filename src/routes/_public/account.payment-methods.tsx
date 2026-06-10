import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  fetchCountries,
  fetchLocale,
  organizationsFullQueryOptions,
  paymentMethodsQueryOptions,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute('/_public/account/payment-methods')({
  head: () => ({ meta: [{ title: pageTitle('Payment methods', 'Account') }] }),
  beforeLoad: () => {
    if (!getActiveProfileFeatures().billing) {
      throw redirect({ to: '/account', replace: true })
    }
  },
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return

    const { queryClient } = context
    await Promise.all([
      queryClient.ensureQueryData(paymentMethodsQueryOptions()),
      queryClient.ensureQueryData(organizationsFullQueryOptions()),
      queryClient.prefetchQuery({
        queryKey: ['countries', 'console'],
        queryFn: fetchCountries,
        staleTime: 5 * 60 * 1000,
      }),
      queryClient.prefetchQuery({
        queryKey: ['locale', 'console'],
        queryFn: fetchLocale,
        staleTime: 5 * 60 * 1000,
      }),
    ])
  },
  component: AccountPaymentMethodsRoute,
})

function AccountPaymentMethodsRoute() {
  return null
}
