import { Link, useNavigate } from '@tanstack/react-router'
import { Lock } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'

const BUDGET_FALLBACK_MESSAGE =
  'This organization has reached its budget limit and is now blocked. To continue using Appwrite services, update the budget limit.'

const OUTSTANDING_INVOICE_FALLBACK_MESSAGE =
  'This project is in readonly mode. Please contact the organization admin for details.'

type BudgetLimitProjectCurtainProps = {
  /** Organization id for billing CTA; may be unresolved briefly after a 402 */
  teamId?: string | null
  /** API message from HTTP 402 when present */
  message?: string | null
  /** API error type from HTTP 402 when present (e.g. outstanding_invoice) */
  errorType?: string | null
}

/**
 * Full-screen curtain shown when billable project APIs return HTTP 402
 * (budget cap, unpaid invoices, or other payment-required blocks).
 */
export function BudgetLimitProjectCurtain({
  teamId,
  message,
  errorType,
}: BudgetLimitProjectCurtainProps) {
  const t = useT()
  const navigate = useNavigate()
  const hasTeamId = !!teamId
  const isOutstandingInvoice =
    errorType === 'outstanding_invoice' ||
    (message?.toLowerCase().includes('readonly mode') ?? false)
  const body =
    message?.trim() ||
    (isOutstandingInvoice
      ? OUTSTANDING_INVOICE_FALLBACK_MESSAGE
      : BUDGET_FALLBACK_MESSAGE)
  const title = isOutstandingInvoice
    ? 'Payment failed'
    : 'Budget limit reached'
  const ctaLabel = isOutstandingInvoice ? 'Fix payment' : 'Update limit'
  const billingHash = isOutstandingInvoice ? undefined : 'update-budget'

  return (
    <div className="fixed inset-0 z-[200] flex items-center justify-center bg-background/95 backdrop-blur-sm">
      <div className="mx-4 flex max-w-md flex-col items-center text-center">
        <div className="mb-6 flex size-16 items-center justify-center rounded-full bg-muted text-muted-foreground">
          <Lock className="size-9" />
        </div>
        <h1 className="text-[22px] font-semibold text-foreground">
          {t(title)}
        </h1>
        <p className="mt-3 text-[15px] text-muted-foreground">{t(body)}</p>

        <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:gap-2">
          {hasTeamId ? (
            <Button asChild variant="brandCta" className="gap-1.5">
              <Link
                to="/organizations/$orgId/settings/billing"
                params={{ orgId: teamId }}
                hash={billingHash}
              >
                {t(ctaLabel)}
              </Link>
            </Button>
          ) : null}
          <Button
            variant={hasTeamId ? 'outline' : 'brandCta'}
            onClick={() => {
              if (hasTeamId) {
                navigate({
                  to: '/organizations/$orgId',
                  params: { orgId: teamId },
                })
                return
              }
              navigate({ to: '/' })
            }}
          >
            {hasTeamId ? t('Back to organization') : t('Back to console')}
          </Button>
        </div>
      </div>
    </div>
  )
}
