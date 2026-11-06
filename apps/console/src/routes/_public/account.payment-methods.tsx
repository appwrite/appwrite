import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  fetchCountries,
  fetchLocale,
  organizationsFullQueryOptions,
  paymentMethodsQueryOptions,
} from '@/lib/react-query/hooks'
import { AccountPaymentMethodsPage } from '@/components/pages/account/PaymentMethodsPage'

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
    const [paymentMethods, organizations] = await Promise.all([
      queryClient.ensureQueryData(paymentMethodsQueryOptions()),
      queryClient.ensureQueryData(organizationsFullQueryOptions()),
    ])

    await Promise.all([
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

    return { paymentMethods, organizations }
  },
  component: AccountPaymentMethodsRoute,
})

function AccountPaymentMethodsRoute() {
  const initialData = Route.useLoaderData()
  return <AccountPaymentMethodsPage initialData={initialData} />
}
