import { PlanSummary } from './PlanSummary'
import { PaymentHistory } from './PaymentHistory'
import { PaymentMethods } from './PaymentMethods'
import { BillingAddressSection } from './BillingAddressSection'
import { TaxIdSection } from './TaxIdSection'
import { BudgetCapSection } from './BudgetCapSection'
import { BillingAlertsSection } from './BillingAlertsSection'
import { AvailableCreditsSection } from './AvailableCreditsSection'

/**
 * BillingTab Component
 *
 * Main container for the billing dashboard that orchestrates all billing sections:
 * 1. Plan Summary - Current plan, charges breakdown, next payment
 * 2. Payment History - Invoice table with pagination
 * 3. Payment Methods - Primary and backup payment methods
 * 4. Billing Address - Stored billing address
 * 5. Tax ID - Tax identification information
 * 6. Budget Cap - Spending limit toggle and configuration
 * 7. Billing Alerts - Usage threshold notifications
 * 8. Available Credits - Credit balance and expiration
 *
 * Props: None
 *
 * Layout:
 * - Responsive grid layout
 * - Full-width sections for tables (Payment History)
 * - Two-column grid for smaller cards on larger screens
 *
 * Data Flow:
 * - All data currently sourced from mock-data.ts
 * - Ready for integration with real API endpoints
 *
 * User Interactions:
 * - Change plan (opens modal - not implemented)
 * - Add/update payment methods (opens modal - not implemented)
 * - Update billing address (opens modal - not implemented)
 * - Update tax ID (opens modal - not implemented)
 * - Toggle budget cap and set limit
 * - Add/remove/toggle billing alerts
 * - Add credits (opens modal - not implemented)
 */

export function BillingTab() {
  // Modal handlers - these would open respective modals
  const handleChangePlan = () => {
    console.log('Open change plan modal')
  }

  const handleAddPaymentMethod = () => {
    console.log('Open add payment method modal')
  }

  const handleEditAddress = () => {
    console.log('Open edit address modal')
  }

  const handleEditTaxId = () => {
    console.log('Open edit tax ID modal')
  }

  const handleAddCredits = () => {
    console.log('Open add credits modal')
  }

  return (
    <div className="space-y-6">
      {/* Plan Summary */}
      <PlanSummary onChangePlan={handleChangePlan} />

      {/* Payment History */}
      <PaymentHistory />

      {/* Payment Methods */}
      <PaymentMethods onAddPaymentMethod={handleAddPaymentMethod} />

      {/* Billing Address */}
      <BillingAddressSection onEditAddress={handleEditAddress} />

      {/* Tax ID */}
      <TaxIdSection onEditTaxId={handleEditTaxId} />

      {/* Budget Cap */}
      <BudgetCapSection />

      {/* Billing Alerts */}
      <BillingAlertsSection />

      {/* Available Credits */}
      <AvailableCreditsSection onAddCredits={handleAddCredits} />
    </div>
  )
}
