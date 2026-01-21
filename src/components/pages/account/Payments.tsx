/**
 * AccountPayments Component
 *
 * Main component for the account payments tab.
 * Displays payment methods and billing addresses at the account level.
 */

import { useState } from 'react'
import { AccountPaymentMethods } from './Payments/PaymentMethods'
import { AccountBillingAddresses } from './Payments/BillingAddresses'
import { PaymentModal } from '../organizations/$orgId/billing/Payment'

export function AccountPayments() {
  const [paymentModalOpen, setPaymentModalOpen] = useState(false)

  const handleAddPaymentMethod = () => {
    setPaymentModalOpen(true)
  }

  const handlePaymentModalSuccess = () => {
    setPaymentModalOpen(false)
  }

  return (
    <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6">
      <div className="space-y-6">
        {/* Payment Methods Section */}
        <AccountPaymentMethods onAddPaymentMethod={handleAddPaymentMethod} />

        {/* Billing Addresses Section */}
        <AccountBillingAddresses />
      </div>

      {/* Payment Modal */}
      <PaymentModal
        open={paymentModalOpen}
        onOpenChange={setPaymentModalOpen}
        onSuccess={handlePaymentModalSuccess}
      />
    </div>
  )
}
