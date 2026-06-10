import { useMemo } from 'react'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { AccountBillingAddresses } from './Payments/BillingAddresses'

export function AccountBillingAddressesPage() {
  const cards = useMemo<SettingsCardItem[]>(
    () => [
      {
        id: 'billing-addresses',
        search: {
          title: 'Billing addresses',
          keywords: ['address', 'country', 'city', 'postal', 'zip', 'street'],
        },
        node: <AccountBillingAddresses />,
      },
    ],
    [],
  )

  return <SettingsCardsList cards={cards} />
}
