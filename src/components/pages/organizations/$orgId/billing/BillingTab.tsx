import { useMemo, useState } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { navigateToUpgradeWizard } from '@/lib/open-upgrade-wizard'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { WarningAlert } from '@/components/global/shared/WarningAlert'
import { Button } from '@/components/ui/button'
import { AlertTriangle, CreditCard } from 'lucide-react'
import {
  asOrganizationPaymentRefs,
  isSubscriptionFailedInvoiceWithError,
} from './utils'
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
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import {
  useOrganizationById,
  useOrganizationPaymentMethod,
  useRetryInvoicePayment,
  resolvePaymentMethodIdForInvoiceRetry,
  isOrganizationBillingReadonlyStatus,
} from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'

export function BillingTab() {
  const t = useT()
  const params = useParams({ strict: false })
  const navigate = useNavigate()
  const orgId = params.orgId as string | undefined

  const [paymentModalOpen, setPaymentModalOpen] = useState(false)
  const [isBackupPaymentMethod, setIsBackupPaymentMethod] = useState(false)
  const [addCreditsModalOpen, setAddCreditsModalOpen] = useState(false)

  const { organization, isLoading: orgLoading } = useOrganizationById(orgId)
  const orgRefs = organization
    ? asOrganizationPaymentRefs(organization)
    : null

  const primaryPaymentMethod = useOrganizationPaymentMethod(
    orgId,
    orgRefs?.paymentMethodId ?? undefined,
  )
  useOrganizationPaymentMethod(
    orgId,
    orgRefs?.backupPaymentMethodId ?? undefined,
  )

  const retryPaymentMutation = useRetryInvoicePayment()

  const failedInvoice = orgRefs?.failedInvoice
  const hasFailedInvoice = isSubscriptionFailedInvoiceWithError(failedInvoice)

  const primaryFailed = primaryPaymentMethod.paymentMethod?.failed === true
  const hasExpiredPaymentMethod =
    primaryFailed && !orgRefs?.backupPaymentMethodId

  const hasPlanDowngrade = !!orgRefs?.billingPlanDowngrade

  const orgBillingReadonly = isOrganizationBillingReadonlyStatus(
    (organization as { status?: string } | null | undefined)?.status,
  )

  const handleRetryPayment = async () => {
    if (!orgId || !failedInvoice || !organization) return

    try {
      const paymentMethodId = await resolvePaymentMethodIdForInvoiceRetry({
        organization: asOrganizationPaymentRefs(organization),
        primaryPaymentMethodFailed: primaryFailed,
      })

      if (!paymentMethodId) {
        toast.error(
          t('No payment method available. Please add a payment method first.'),
        )
        return
      }

      await retryPaymentMutation.mutateAsync({
        organizationId: orgId,
        invoiceId: failedInvoice.$id,
        paymentMethodId,
      })

      toast.success(t('Payment retry initiated'))
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : t('Failed to retry payment'),
      )
    }
  }

  const handleChangePlan = () => {
    navigateToUpgradeWizard(navigate, orgId)
  }

  const handleAddPaymentMethod = (isBackup = false) => {
    setIsBackupPaymentMethod(isBackup)
    setPaymentModalOpen(true)
  }

  const handlePaymentModalSuccess = () => {
    setPaymentModalOpen(false)
  }

  const handleEditTaxId = () => {
    // TODO: Open edit tax ID modal
  }

  const handleAddCredits = () => {
    setAddCreditsModalOpen(true)
  }

  const cards = useMemo((): SettingsCardItem[] => {
    const items: SettingsCardItem[] = []

    if (!orgLoading) {
      if (hasFailedInvoice) {
        items.push({
          id: 'alert-failed-invoice',
          search: {
            title: 'Payment failed',
            keywords: [
              'failed',
              'retry',
              'outstanding',
              'read-only',
              'invoice',
            ],
          },
          node: (
            <WarningAlert
              title={
                orgBillingReadonly
                  ? t('Payment failed - organization has restricted access')
                  : t('Payment failed')
              }
            >
              {orgBillingReadonly && (
                <p className="mb-2 font-medium text-red-600 dark:text-red-400">
                  {t(
                    'Changes to projects and services are limited until the outstanding invoice is paid. Complete payment to restore full access.',
                  )}
                </p>
              )}
              {failedInvoice.lastError ||
                t(
                  'Your last payment attempt failed. Please update your payment method and try again.',
                )}
              <div className="mt-3">
                <Button
                  size="sm"
                  className="h-8 bg-red-500 px-3 text-[12px] font-medium text-red-50 hover:bg-red-400"
                  onClick={handleRetryPayment}
                  disabled={retryPaymentMutation.isPending}
                >
                  {t('Try again')}
                </Button>
              </div>
            </WarningAlert>
          ),
        })
      }

      if (hasExpiredPaymentMethod) {
        items.push({
          id: 'alert-expired-payment-method',
          search: {
            title: 'Payment method failed',
            keywords: ['expired', 'declined', 'failed card', 'backup'],
          },
          node: (
            <WarningAlert title={t('Payment method failed')} icon={CreditCard}>
              {t(
                "Your default payment method has failed and you don't have a backup method. Please add a new payment method to continue using our services.",
              )}
            </WarningAlert>
          ),
        })
      }

      if (hasPlanDowngrade) {
        items.push({
          id: 'alert-plan-downgrade',
          search: {
            title: 'Plan downgrade scheduled',
            keywords: ['downgrade', 'scheduled', 'end of period', 'plan'],
          },
          node: (
            <Alert>
              <AlertTriangle className="h-4 w-4" />
              <AlertTitle>{t('Plan downgrade scheduled')}</AlertTitle>
              <AlertDescription className="mt-2">
                {t(
                  "Your plan will change at the end of your current billing period. You'll keep access to your current plan features until then.",
                )}
              </AlertDescription>
            </Alert>
          ),
        })
      }
    }

    items.push(
      {
        id: 'plan-summary',
        search: {
          title: 'Current plan',
          keywords: [
            'plan',
            'subscription',
            'tier',
            'upgrade',
            'downgrade',
            'change plan',
      'pro',
      'scale',
      'core',
      'free',
            'next payment',
            'charges',
            'billing cycle',
          ],
        },
        node: <PlanSummary onChangePlan={handleChangePlan} orgId={orgId} />,
      },
      {
        id: 'payment-history',
        search: {
          title: 'Payment history',
          keywords: ['invoice', 'invoices', 'receipt', 'payment history', 'paid'],
        },
        node: <PaymentHistory />,
      },
      {
        id: 'payment-methods',
        search: {
          title: 'Payment methods',
          keywords: [
            'card',
            'credit card',
            'stripe',
            'backup',
            'default payment',
            'payment method',
            'add payment',
          ],
        },
        node: (
          <PaymentMethods
            onAddPaymentMethod={handleAddPaymentMethod}
            orgId={orgId}
          />
        ),
      },
      {
        id: 'billing-address',
        search: {
          title: 'Billing address',
          keywords: ['address', 'country', 'city', 'postal', 'zip', 'street'],
        },
        node: <BillingAddressSection orgId={orgId} />,
      },
      {
        id: 'tax-id',
        search: {
          title: 'Tax ID',
          keywords: ['vat', 'tax', 'ein', 'gst', 'identification'],
        },
        node: <TaxIdSection onEditTaxId={handleEditTaxId} orgId={orgId} />,
      },
      {
        id: 'budget-cap',
        search: {
          title: 'Budget cap',
          keywords: ['budget', 'spending limit', 'cap', 'overage', 'usage limit'],
        },
        node: <BudgetCapSection orgId={orgId} />,
      },
      {
        id: 'billing-alerts',
        search: {
          title: 'Billing alerts',
          keywords: ['alerts', 'threshold', 'notification', 'usage', 'email'],
        },
        node: <BillingAlertsSection orgId={orgId} />,
      },
      {
        id: 'available-credits',
        search: {
          title: 'Available credits',
          keywords: ['credits', 'balance', 'coupon', 'promo', 'prepaid', 'add credits'],
        },
        node: (
          <AvailableCreditsSection
            onAddCredits={handleAddCredits}
            orgId={orgId}
          />
        ),
      },
    )

    return items
  }, [
    orgLoading,
    hasFailedInvoice,
    hasExpiredPaymentMethod,
    hasPlanDowngrade,
    orgBillingReadonly,
    failedInvoice,
    retryPaymentMutation.isPending,
    orgId,
    t,
  ])

  return (
    <>
      <SettingsCardsList cards={cards} />

      <PaymentModal
        open={paymentModalOpen}
        onOpenChange={setPaymentModalOpen}
        organizationId={orgId}
        isBackup={isBackupPaymentMethod}
        onSuccess={handlePaymentModalSuccess}
      />

      {orgId && (
        <AddCreditsModal
          open={addCreditsModalOpen}
          onOpenChange={setAddCreditsModalOpen}
          organizationId={orgId}
          organizationName={organization?.name}
          onSuccess={() => setAddCreditsModalOpen(false)}
        />
      )}
    </>
  )
}
