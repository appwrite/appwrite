import { useMemo } from 'react'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { AccountBillingAddresses } from './Payments/BillingAddresses'
import type { Models } from '@appwrite.io/console'
import type { fetchBillingAddresses } from '@/lib/react-query/hooks'

export type AccountBillingAddressesInitialData = {
  addresses?: Awaited<ReturnType<typeof fetchBillingAddresses>>
  organizations?: Models.Organization[]
}

export function AccountBillingAddressesPage({
  initialData,
}: {
  initialData?: AccountBillingAddressesInitialData
} = {}) {
  const cards = useMemo<SettingsCardItem[]>(
    () => [
      {
        id: 'billing-addresses',
        search: {
          title: 'Billing addresses',
          keywords: ['address', 'country', 'city', 'postal', 'zip', 'street'],
        },
        node: <AccountBillingAddresses initialData={initialData} />,
      },
    ],
    [initialData],
  )

  return <SettingsCardsList cards={cards} />
}
