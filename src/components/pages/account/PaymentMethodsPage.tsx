import { useMemo, useState } from 'react'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { AccountPaymentMethods } from './Payments/PaymentMethods'
import { PaymentModal } from '../organizations/$orgId/billing/Payment'

export function AccountPaymentMethodsPage() {
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
            onAddPaymentMethod={() => setPaymentModalOpen(true)}
          />
        ),
      },
    ],
    [],
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
