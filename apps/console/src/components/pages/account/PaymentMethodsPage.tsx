import { useMemo, useState } from 'react'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { AccountPaymentMethods } from './Payments/PaymentMethods'
import { PaymentModal } from '../organizations/$orgId/billing/Payment'
import type { Models } from '@appwrite.io/console'
import type { fetchPaymentMethods } from '@/lib/react-query/hooks'

export type AccountPaymentMethodsInitialData = {
  paymentMethods?: Awaited<ReturnType<typeof fetchPaymentMethods>>
  organizations?: Models.Organization[]
}

export function AccountPaymentMethodsPage({
  initialData,
}: {
  initialData?: AccountPaymentMethodsInitialData
} = {}) {
  const [paymentModalOpen, setPaymentModalOpen] = useState(false)

  const cards = useMemo<SettingsCardItem[]>(
    () => [
      {
        id: 'payment-methods',
        search: {
          title: 'Payment methods',
          keywords: ['card', 'credit card', 'stripe', 'payment method'],
        },
        node: (
          <AccountPaymentMethods
            initialData={initialData}
            onAddPaymentMethod={() => setPaymentModalOpen(true)}
          />
        ),
      },
    ],
    [initialData],
  )

  return (
    <>
      <SettingsCardsList cards={cards} />
      <PaymentModal
        open={paymentModalOpen}
        onOpenChange={setPaymentModalOpen}
        onSuccess={() => setPaymentModalOpen(false)}
      />
    </>
  )
}
