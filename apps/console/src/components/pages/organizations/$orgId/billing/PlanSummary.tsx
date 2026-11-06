import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import {
  ChevronDown,
  ChevronUp,
  ArrowUpCircle,
  ArrowLeftRight,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { formatCurrency, formatDate } from './utils'
import { cn } from '@/lib/utils'
import {
  useOrganizationById,
  useOrganizationPlan,
  organizationInvoiceQueryOptions,
  useOrganizationScopes,
  canSeeOrganizationBilling,
} from '@/lib/react-query/hooks'
import {
  getPlanNameFromTier,
  isSelfServiceProPlan,
  resolveOrganizationPlanDisplayLabel,
} from '@/lib/utils/plan-filter'
import { ContactSalesLink } from '@/components/global/shared/ContactSalesLink'
import { EnterpriseBadge } from '@/components/global/shared/EnterpriseBadge'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'

interface PlanSummaryProps {
  onChangePlan?: () => void
  orgId?: string
}

export function PlanSummary({ onChangePlan, orgId }: PlanSummaryProps) {
  const t = useT()
  const [expanded, setExpanded] = useState(true)
  const { organization } = useOrganizationById(orgId)
  const { plan } = useOrganizationPlan(orgId)
  const { access } = useOrganizationScopes(orgId, undefined, {
    projectId: null,
  })
  const canReadInvoice = canSeeOrganizationBilling(access)
  const { data: invoice, error } = useQuery({
    ...organizationInvoiceQueryOptions(orgId, organization?.billingInvoiceId),
    enabled: canReadInvoice && !!orgId && !!organization?.billingInvoiceId,
  })
  const planMatchesOrg =
    !plan?.$id ||
    plan.$id === organization?.billingPlan ||
    getPlanNameFromTier(plan.$id) ===
      getPlanNameFromTier(organization?.billingPlan)
  const planName = resolveOrganizationPlanDisplayLabel({
    billingPlan: organization?.billingPlan,
    planName: planMatchesOrg ? plan?.name : null,
    planId: planMatchesOrg ? plan?.$id : organization?.billingPlan,
  })
  const hasRecordedCharges =
    !!invoice &&
    (!!invoice.items?.length || !['pending', 'trial'].includes(invoice.status))
  const basePlanPrice = plan?.price ?? 0
  // Saved lines include pricing adjustments and tax. Account credits settle them separately.
  const chargesInCents = invoice?.items?.length
    ? invoice.items.reduce((total, item) => total + item.amount, 0)
    : Math.round(
        ((invoice?.grossAmount ?? 0) + (invoice?.creditsUsed ?? 0)) * 100,
      )
  const creditsInCents = Math.round((invoice?.creditsUsed ?? 0) * 100)
  const charges = chargesInCents / 100
  const creditsApplied = creditsInCents / 100
  const totalAmount = Math.max(chargesInCents - creditsInCents, 0) / 100
  const currency = invoice?.currency || 'USD'
  const showEnterpriseNote = isSelfServiceProPlan(plan) && charges >= 50
  const billingCycle =
    organization?.billingCurrentInvoiceDate &&
    organization?.billingNextInvoiceDate
      ? {
          start: organization.billingCurrentInvoiceDate,
          end: organization.billingNextInvoiceDate,
        }
      : null
  const nextPaymentDate = invoice?.dueAt ?? billingCycle?.end
  const billingCycleLabel = t('Monthly')

  if (error) throw error
  if (!canReadInvoice) return null

  return (
    <div
      id="current-cycle-usage"
      className="rounded-xl border border-border bg-card/50 overflow-hidden scroll-mt-24"
    >
      {/* Header */}
      <div className="px-6 py-4">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <h3 className="text-[15px] font-semibold text-foreground">
                {planName} {t('plan')}
              </h3>
              <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary">
                {billingCycleLabel}
              </span>
            </div>
            {hasRecordedCharges &&
              ['pending', 'trial'].includes(invoice?.status ?? '') &&
              totalAmount > 0 &&
              nextPaymentDate && (
                <p className="text-[12px] text-muted-foreground mt-1">
                  {t('Next payment of')}{' '}
                  <span className="font-medium text-foreground">
                    {formatCurrency(totalAmount, currency)}
                  </span>{' '}
                  {t('will occur on')}{' '}
                  <span className="font-medium text-foreground">
                    {formatDate(nextPaymentDate, {
                      month: 'short',
                      day: 'numeric',
                      year: 'numeric',
                    })}
                  </span>
                  .
                </p>
              )}
          </div>
          <div className="text-end shrink-0 max-w-[240px] sm:max-w-xs">
            <p className="text-[12px] leading-snug text-muted-foreground">
              {t('Usage-based estimate; updates may take up to 4 hours.')}
            </p>
          </div>
        </div>
      </div>

      {/* Billing Cycle */}
      {billingCycle && (
        <div className="border-t border-border px-6 py-3 bg-muted/30">
          <div className="flex items-center justify-between text-[12px]">
            <span className="text-muted-foreground">
              {t('Current billing cycle')}
            </span>
            <span className="font-medium text-foreground">
              (
              {formatDate(billingCycle.start, {
                month: 'short',
                day: 'numeric',
              })}
              –
              {formatDate(billingCycle.end, { month: 'short', day: 'numeric' })}
              )
            </span>
          </div>
        </div>
      )}

      {/* Expandable Charges Breakdown */}
      <div className="border-t border-border">
        <button
          onClick={() => setExpanded(!expanded)}
          className="flex w-full items-center justify-between gap-3 px-6 py-3 text-[13px] text-muted-foreground hover:bg-accent/50 transition-colors"
        >
          <span className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5 text-start">
            <span>{t('View charges breakdown')}</span>
            {invoice?.$updatedAt ? (
              <>
                <span className="text-border hidden sm:inline" aria-hidden>
                  ·
                </span>
                <span className="text-[11px]">
                  {t('Last updated')}:{' '}
                  <DateTooltip
                    date={invoice.$updatedAt}
                    live
                    className="text-muted-foreground"
                  />
                </span>
              </>
            ) : null}
          </span>
          {expanded ? (
            <ChevronUp className="h-4 w-4 shrink-0" />
          ) : (
            <ChevronDown className="h-4 w-4 shrink-0" />
          )}
        </button>

        <div
          className={cn(
            'transition-all duration-200',
            expanded
              ? 'max-h-none overflow-visible'
              : 'max-h-0 overflow-hidden',
          )}
        >
          <div className="border-t border-border px-6 py-4 space-y-4">
            {invoice?.items?.length ? (
              invoice.items.map((item, index) => (
                <div
                  key={index}
                  className="flex items-start justify-between gap-4 text-[13px]"
                >
                  <div className="min-w-0 space-y-1">
                    <p className="text-foreground">{item.description}</p>
                    {item.projectId && (
                      <Link
                        to="/projects/$projectId/usage"
                        params={{ projectId: item.projectId }}
                        className="text-[12px] link-neutral"
                      >
                        {item.projectId}
                      </Link>
                    )}
                    {(item.from || item.to) && (
                      <p className="text-[12px] text-muted-foreground">
                        {item.from && (
                          <DateTooltip date={item.from} showFormattedDate />
                        )}
                        {item.from && item.to && ' - '}
                        {item.to && (
                          <DateTooltip date={item.to} showFormattedDate />
                        )}
                      </p>
                    )}
                  </div>
                  <span className="font-medium text-foreground whitespace-nowrap">
                    {formatCurrency(item.amount / 100, currency)}
                  </span>
                </div>
              ))
            ) : (
              <div className="flex items-start justify-between gap-4 text-[13px]">
                <span className="text-muted-foreground">
                  {t(
                    !invoice
                      ? 'No invoice yet.'
                      : hasRecordedCharges
                        ? 'Recorded charges; detailed pricing unavailable.'
                        : 'Invoice breakdown unavailable.',
                  )}
                </span>
                {hasRecordedCharges && (
                  <span className="font-medium text-foreground">
                    {formatCurrency(charges, currency)}
                  </span>
                )}
              </div>
            )}
            {creditsApplied > 0 && (
              <div className="flex items-center justify-between text-[13px]">
                <span className="text-muted-foreground">
                  {t('Credits applied')}
                </span>
                <span className="font-medium text-foreground">
                  {formatCurrency(-creditsApplied, currency)}
                </span>
              </div>
            )}
            {hasRecordedCharges && (
              <div className="flex items-center justify-between border-t border-border pt-3 text-[13px]">
                <span className="font-medium text-foreground">
                  {t('Total')}
                </span>
                <span className="font-semibold text-foreground">
                  {formatCurrency(totalAmount, currency)}
                </span>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Footer */}
      {plan?.selfService !== false && (
        <div className="border-t border-border px-6 py-4 bg-muted/30">
          <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
            {(basePlanPrice ?? 0) === 0 ? (
              <Button
                size="sm"
                className="h-9 text-[13px] gap-1.5"
                onClick={onChangePlan}
                {...analyticsAttrs('upgrade-clicked')}
              >
                <ArrowUpCircle className="h-4 w-4" />
                {t('Upgrade')}
              </Button>
            ) : (
              <Button
                variant="outline"
                size="sm"
                className="h-9 text-[13px] gap-1.5"
                onClick={onChangePlan}
                {...analyticsAttrs('billing-change-plan')}
              >
                <ArrowLeftRight className="h-4 w-4" />
                {t('Change plan')}
              </Button>
            )}
            {showEnterpriseNote && (
              <p className="ms-auto text-[12px] text-muted-foreground">
                <EnterpriseBadge />
                {t('Need a custom SLA or a private Slack channel?')}{' '}
                <ContactSalesLink
                  source="billing"
                  orgId={orgId}
                  className="font-medium text-foreground underline hover:no-underline"
                >
                  {t('Talk to sales')}
                </ContactSalesLink>
              </p>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
