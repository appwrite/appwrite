import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  billingAddressesQueryOptions,
  countriesQueryOptions,
  organizationsFullQueryOptions,
} from '@/lib/react-query/hooks'
import { AccountBillingAddressesPage } from '@/components/pages/account/BillingAddressesPage'

export const Route = createFileRoute('/_public/account/billing-addresses')({
  head: () => ({ meta: [{ title: pageTitle('Billing addresses', 'Account') }] }),
  beforeLoad: () => {
    if (!getActiveProfileFeatures().billing) {
      throw redirect({ to: '/account', replace: true })
    }
  },
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return

    const { queryClient } = context
    const [addresses, organizations] = await Promise.all([
      queryClient.ensureQueryData(billingAddressesQueryOptions()),
      queryClient.ensureQueryData(organizationsFullQueryOptions()),
    ])
    await queryClient.ensureQueryData(countriesQueryOptions())

    return { addresses, organizations }
  },
  component: AccountBillingAddressesRoute,
})

function AccountBillingAddressesRoute() {
  const initialData = Route.useLoaderData()
  return <AccountBillingAddressesPage initialData={initialData} />
}
