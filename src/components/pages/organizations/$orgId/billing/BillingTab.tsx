import { useState } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { AlertTriangle, CreditCard } from 'lucide-react'
import { PlanSummary } from './PlanSummary'
import { PaymentHistory } from './PaymentHistory'
import { PaymentMethods } from './PaymentMethods'
import { PaymentModal } from './Payment'
import { BillingAddressSection } from './BillingAddressSection'
import { AddCreditsModal } from './AddCreditsModal'
import { TaxIdSection } from './TaxIdSection'
import { BudgetCapSection } from './BudgetCapSection'
import { BillingAlertsSection } from './BillingAlertsSection'
import { AvailableCreditsSection } from './AvailableCreditsSection'
import {
  useOrganizationById,
  usePaymentMethod,
  useRetryInvoicePayment,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { toast } from 'sonner'

/**
 * BillingTab Component
 *
 * Main container for the billing dashboard that orchestrates all billing sections:
 * 1. Alert Messages - Failed invoices, expired payment methods, plan downgrades
 * 2. Plan Summary - Current plan, charges breakdown, next payment
 * 3. Payment History - Invoice table with pagination
 * 4. Payment Methods - Primary and backup payment methods
 * 5. Billing Address - Stored billing address
 * 6. Tax ID - Tax identification information
 * 7. Budget Cap - Spending limit toggle and configuration
 * 8. Billing Alerts - Usage threshold notifications
 * 9. Available Credits - Credit balance and expiration
 */

export function BillingTab() {
  const params = useParams({ strict: false })
  const navigate = useNavigate()
  const orgId = params.orgId as string | undefined

  // Payment modal state
  const [paymentModalOpen, setPaymentModalOpen] = useState(false)
  const [isBackupPaymentMethod, setIsBackupPaymentMethod] = useState(false)
  const [addCreditsModalOpen, setAddCreditsModalOpen] = useState(false)

  // Fetch organization data for alerts
  const { organization, isLoading: orgLoading } = useOrganizationById(orgId)

  // Fetch payment methods for alert checking
  const primaryPaymentMethod = usePaymentMethod(organization?.paymentMethodId)
  usePaymentMethod(organization?.backupPaymentMethodId)

  const retryPaymentMutation = useRetryInvoicePayment()

  // Check for failed invoice
  const failedInvoice = organization?.failedInvoice
  const hasFailedInvoice = failedInvoice && failedInvoice.lastError

  // Check for expired payment method
  const primaryFailed = primaryPaymentMethod.paymentMethod?.failed === true
  const hasExpiredPaymentMethod =
    primaryFailed && !organization?.backupPaymentMethodId

  // Check for plan downgrade
  const hasPlanDowngrade = !!organization?.billingPlanDowngrade

  const handleRetryPayment = async () => {
    if (!orgId || !failedInvoice) return

    try {
      // Determine which payment method to use
      let paymentMethodId = organization.paymentMethodId
      if (!paymentMethodId || primaryFailed) {
        paymentMethodId = organization.backupPaymentMethodId
      }
      if (!paymentMethodId) {
        // Get first available payment method from account
        const paymentMethods = await sdk.forConsole.account.listPaymentMethods()
        if (
          paymentMethods.paymentMethods &&
          paymentMethods.paymentMethods.length > 0
        ) {
          paymentMethodId = paymentMethods.paymentMethods[0].$id
        }
      }

      if (!paymentMethodId) {
        toast.error(
          'No payment method available. Please add a payment method first.',
        )
        return
      }

      await retryPaymentMutation.mutateAsync({
        organizationId: orgId,
        invoiceId: failedInvoice.$id,
        paymentMethodId,
      })

      toast.success('Payment retry initiated')
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : 'Failed to retry payment',
      )
    }
  }

  // Modal handlers
  const handleChangePlan = () => {
    if (orgId) {
      navigate({
        to: '/organizations/$orgId/change-plan',
        params: { orgId },
      })
    }
  }

  const handleAddPaymentMethod = (isBackup = false) => {
    setIsBackupPaymentMethod(isBackup)
    setPaymentModalOpen(true)
  }

  const handlePaymentModalSuccess = () => {
    // Payment method will be automatically assigned if organizationId is provided
    setPaymentModalOpen(false)
  }

  const handleEditTaxId = () => {
    // TODO: Open edit tax ID modal
  }

  const handleAddCredits = () => {
    setAddCreditsModalOpen(true)
  }

  return (
    <div className="space-y-6">
      {/* Alert Messages */}
      {!orgLoading && (
        <>
          {/* Failed Invoice Alert */}
          {hasFailedInvoice && (
            <Alert variant="default" className="border-red-500/30 bg-red-500/5">
              <AlertTriangle className="h-4 w-4 text-red-500" />
              <AlertTitle className="text-[13px] font-medium text-red-600 dark:text-red-400">
                Payment Failed
              </AlertTitle>
              <AlertDescription className="mt-2 text-[12px] text-red-600/80 dark:text-red-400/80">
                {failedInvoice.lastError ||
                  'Your last payment attempt failed. Please update your payment method and try again.'}
                <div className="mt-3">
                  <Button
                    size="sm"
                    className="h-8 bg-red-500 px-3 text-[12px] font-medium text-red-50 hover:bg-red-400"
                    onClick={handleRetryPayment}
                    disabled={retryPaymentMutation.isPending}
                  >
                    Try again
                  </Button>
                </div>
              </AlertDescription>
            </Alert>
          )}

          {/* Expired Payment Method Alert */}
          {hasExpiredPaymentMethod && (
            <Alert variant="default" className="border-red-500/30 bg-red-500/5">
              <CreditCard className="h-4 w-4 text-red-500" />
              <AlertTitle className="text-[13px] font-medium text-red-600 dark:text-red-400">
                Payment Method Failed
              </AlertTitle>
              <AlertDescription className="mt-2 text-[12px] text-red-600/80 dark:text-red-400/80">
                Your default payment method has failed and you don't have a
                backup method. Please add a new payment method to continue using
                our services.
              </AlertDescription>
            </Alert>
          )}

          {/* Plan Downgrade Alert */}
          {hasPlanDowngrade && (
            <Alert>
              <AlertTriangle className="h-4 w-4" />
              <AlertTitle>Plan Downgrade Scheduled</AlertTitle>
              <AlertDescription className="mt-2">
                Your plan will change at the end of your current billing period.
                You'll keep access to your current plan features until then.
              </AlertDescription>
            </Alert>
          )}
        </>
      )}

      {/* Plan Summary */}
      <PlanSummary onChangePlan={handleChangePlan} orgId={orgId} />

      {/* Payment History */}
      <PaymentHistory />

      {/* Payment Methods */}
      <PaymentMethods
        onAddPaymentMethod={handleAddPaymentMethod}
        orgId={orgId}
      />

      {/* Billing Address */}
      <BillingAddressSection orgId={orgId} />

      {/* Tax ID */}
      <TaxIdSection onEditTaxId={handleEditTaxId} orgId={orgId} />

      {/* Budget Cap */}
      <BudgetCapSection orgId={orgId} />

      {/* Billing Alerts */}
      <BillingAlertsSection orgId={orgId} />

      {/* Available Credits */}
      <AvailableCreditsSection onAddCredits={handleAddCredits} orgId={orgId} />

      {/* Payment Modal */}
      <PaymentModal
        open={paymentModalOpen}
        onOpenChange={setPaymentModalOpen}
        organizationId={orgId}
        isBackup={isBackupPaymentMethod}
        onSuccess={handlePaymentModalSuccess}
      />

      {/* Add Credits Modal */}
      {orgId && (
        <AddCreditsModal
          open={addCreditsModalOpen}
          onOpenChange={setAddCreditsModalOpen}
          organizationId={orgId}
          organizationName={organization?.name}
          onSuccess={() => setAddCreditsModalOpen(false)}
        />
      )}
    </div>
  )
}
