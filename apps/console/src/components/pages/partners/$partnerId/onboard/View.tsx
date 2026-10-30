import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  HandoffResourcePicker,
  type HandoffSelection,
} from '@/components/pages/partners/$partnerId/_components/HandoffResourcePicker'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Switch } from '@/components/ui/switch'
import { PaymentMethodDropdown } from '@/components/pages/organizations/$orgId/billing/change-plan/PaymentMethodDropdown'
import { PaymentModal } from '@/components/pages/organizations/$orgId/billing/Payment'
import { formatCurrency } from '@/components/pages/organizations/$orgId/billing/utils'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { getPlanAddonOverage } from '@/lib/billing/plan-addon-charge'
import { BillingPlanTier } from '@/lib/constants/billing-plan'
import { useT } from '@/lib/i18n/translate'
import {
  PARTNER_CLIENT_PLANS,
  type PartnerAwareOrganization,
} from '@/lib/partners'
import {
  billingPlansQueryOptions,
  paymentMethodsQueryOptions,
  useOrganizationById,
  usePaymentMethods,
} from '@/lib/react-query/hooks'
import {
  createPartnerClient,
  partnerClientCreateErrorMessage,
} from '@/lib/react-query/hooks/partners'
import { cn } from '@/lib/utils'
import {
  getPlanCanonicalFromRecord,
  resolveOrganizationPlanDisplayLabel,
  type BillingPlanRecord,
} from '@/lib/utils/plan-filter'

type PlanCatalog = Record<
  string,
  BillingPlanRecord &
    Pick<Models.BillingPlan, 'addons' | 'projects' | 'price'> & {
      desc?: string
      description?: string
    }
>

const PARTNER_PLAN_CANONICALS = new Set(['pro', 'core'])
const DEFAULT_BUDGET_CAP_USD = 200

function partnerEligiblePlans(plans: PlanCatalog): PlanCatalog {
  return Object.fromEntries(
    Object.entries(plans).filter(([planId, plan]) =>
      PARTNER_PLAN_CANONICALS.has(
        getPlanCanonicalFromRecord(planId, {
          ...plans,
          [planId]: plan,
        }),
      ),
    ),
  ) as PlanCatalog
}

export function View({ partnerId }: { partnerId: string }) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { organization } = useOrganizationById(partnerId)
  const partner = organization as PartnerAwareOrganization | null
  const { data: billingPlansData, isLoading: plansLoading } = useQuery(
    billingPlansQueryOptions(),
  )
  const catalog = useMemo(
    () => partnerEligiblePlans((billingPlansData?.plans ?? {}) as PlanCatalog),
    [billingPlansData?.plans],
  )
  const catalogEntries = useMemo(() => Object.entries(catalog), [catalog])
  const fallbackEntries = useMemo(
    () =>
      PARTNER_CLIENT_PLANS.map(
        (item) =>
          [item.id, { name: item.label, price: 0 } as PlanCatalog[string]] as [
            string,
            PlanCatalog[string],
          ],
      ),
    [],
  )
  const planEntries = catalogEntries.length > 0 ? catalogEntries : fallbackEntries

  const defaultPlanId =
    planEntries.find(([id]) => getPlanCanonicalFromRecord(id, catalog) === 'pro')?.[0] ??
    planEntries[0]?.[0] ??
    BillingPlanTier.Tier1

  const [name, setName] = useState('')
  const [selectedPlan, setSelectedPlan] = useState(defaultPlanId)
  const [budgetEnabled, setBudgetEnabled] = useState(false)
  const [budgetValue, setBudgetValue] = useState('')
  const [handoffItems, setHandoffItems] = useState<HandoffSelection[]>([])
  const [paymentMethodId, setPaymentMethodId] = useState('')
  const [paymentModalOpen, setPaymentModalOpen] = useState(false)
  const { paymentMethods } = usePaymentMethods()

  useEffect(() => {
    if (!selectedPlan || !planEntries.some(([id]) => id === selectedPlan)) {
      setSelectedPlan(defaultPlanId)
    }
  }, [defaultPlanId, planEntries, selectedPlan])

  useEffect(() => {
    if (paymentMethodId) return
    if (partner?.paymentMethodId) {
      setPaymentMethodId(partner.paymentMethodId)
      return
    }
    const completed = paymentMethods.find((method) => method.last4)
    if (completed) setPaymentMethodId(completed.$id)
  }, [partner?.paymentMethodId, paymentMethods, paymentMethodId])

  const handoffCounts = useMemo(() => {
    const counts = { project: 0, domain: 0, app: 0 }
    for (const item of handoffItems) {
      counts[item.kind] += 1
    }
    return counts
  }, [handoffItems])
  const selectedProjectCount = handoffCounts.project
  const selectedPlanRecord = catalog[selectedPlan]
  const selectedPlanLabel = resolveOrganizationPlanDisplayLabel({
    billingPlan: selectedPlan,
    planName: selectedPlanRecord?.name ?? PARTNER_CLIENT_PLANS.find((item) => item.id === selectedPlan)?.label,
    planId: selectedPlanRecord?.$id,
  })
  const planPrice = selectedPlanRecord?.price ?? 0
  const projectOverage = getPlanAddonOverage(
    selectedPlanRecord as Models.BillingPlan | undefined,
    'projects',
    selectedProjectCount,
  )
  const estimatedMonthlyTotal = planPrice + projectOverage.amount
  const hasPaymentMethod = Boolean(paymentMethodId)
  const trimmedName = name.trim()
  const budget =
    budgetEnabled && budgetValue.trim() ? Number(budgetValue) : undefined
  const budgetInvalid =
    budgetEnabled &&
    (budgetValue.trim() === '' || !Number.isFinite(budget) || Number(budget) < 1)

  const fallbackPath = `/partners/${partnerId}`

  const createMutation = useMutation({
    mutationFn: async () => {
      const client = await createPartnerClient({
        partnerId,
        name: trimmedName,
        billingPlan: selectedPlan,
        budget: budgetInvalid ? undefined : budget,
        paymentMethodId: paymentMethodId || undefined,
      })
      if (!client?.$id) {
        throw new Error(t('Could not create client'))
      }

      let transferred = 0
      for (const item of handoffItems) {
        try {
          if (item.kind === 'project') {
            await sdk.forConsole.projects.updateTeam({
              projectId: item.id,
              teamId: client.$id,
            })
          } else if (item.kind === 'domain') {
            await sdk.forConsole.domains.updateTeam({
              domainId: item.id,
              teamId: client.$id,
            })
          } else {
            await sdk.forConsole.apps.updateTeam({
              appId: item.id,
              teamId: client.$id,
            })
          }
          transferred += 1
        } catch {
          // Continue remaining transfers; surface a partial-failure toast below.
        }
      }

      return { client, transferred }
    },
    onSuccess: async ({ client, transferred }) => {
      const requested = handoffItems.length
      if (requested === 0 || transferred === requested) {
        toast.success(
          requested === 0
            ? t('Client organization created')
            : t('Client organization created and resources handed off'),
        )
      } else if (transferred > 0) {
        toast.success(
          t('Client created. Some resources could not be handed off.'),
        )
      } else {
        toast.success(t('Client organization created'))
        toast.error(t('Could not hand off the selected resources.'))
      }

      await queryClient.invalidateQueries({ queryKey: ['partners', partnerId] })
      await queryClient.invalidateQueries({
        queryKey: ['organizations', 'console'],
      })
      await queryClient.invalidateQueries({ queryKey: ['projects'] })
      await queryClient.invalidateQueries({
        queryKey: ['projects', 'active', partnerId],
      })
      await queryClient.invalidateQueries({
        queryKey: ['organization-projects', partnerId],
      })
      await queryClient.invalidateQueries({
        queryKey: ['domains', 'organization', partnerId],
      })
      await queryClient.invalidateQueries({
        queryKey: ['apps', 'organization', partnerId],
      })

      navigate({
        to: '/partners/$partnerId/clients/$clientId',
        params: { partnerId, clientId: client.$id },
      })
    },
    onError: (error) => {
      toast.error(t(partnerClientCreateErrorMessage(error)))
    },
  })

  const submitDisabled =
    !trimmedName ||
    !selectedPlan ||
    budgetInvalid ||
    !hasPaymentMethod ||
    createMutation.isPending

  const submitBlockReason = !trimmedName
    ? t('Enter an organization name.')
    : !hasPaymentMethod
      ? t('Select a payment method')
      : budgetInvalid
        ? t('Enter a monthly budget of at least $1.')
        : undefined

  return (
    <WizardLayout
      title={t('New client')}
      fullscreen
      fallbackPath={fallbackPath}
      footerAlign="right"
      sidebar={
        <OnboardSummary
          planLabel={selectedPlanLabel}
          planPrice={planPrice}
          additionalProjectsCount={projectOverage.additionalCount}
          additionalProjectsCost={projectOverage.amount}
          estimatedMonthlyTotal={estimatedMonthlyTotal}
          budgetEnabled={budgetEnabled}
          budgetValue={budgetValue}
          onBudgetEnabledChange={(enabled) => {
            setBudgetEnabled(enabled)
            if (!enabled) {
              setBudgetValue('')
              return
            }
            if (!budgetValue.trim()) {
              setBudgetValue(String(DEFAULT_BUDGET_CAP_USD))
            }
          }}
          onBudgetValueChange={setBudgetValue}
          handoffCounts={handoffCounts}
          partnerName={partner?.name}
          hasPaymentMethod={hasPaymentMethod}
        />
      }
      footer={
        <>
          {submitBlockReason && !createMutation.isPending ? (
            <p
              className="me-auto text-start text-[13px] text-muted-foreground"
              role="status"
            >
              {submitBlockReason}
            </p>
          ) : null}
          <Button
            variant="outline"
            onClick={() =>
              navigate({
                to: '/partners/$partnerId',
                params: { partnerId },
              })
            }
            disabled={createMutation.isPending}
            {...analyticsAttrs('onboard-client-cancel')}
          >
            {t('Cancel')}
          </Button>
          <Button
            onClick={() => createMutation.mutate()}
            disabled={submitDisabled}
            {...analyticsAttrs('onboard-client')}
          >
            {t('Create client')}
          </Button>
        </>
      }
    >
      <div className="space-y-2">
        <Label htmlFor="client-organization-name">
          {t('Name')} <span className="text-destructive">*</span>
        </Label>
        <Input
          id="client-organization-name"
          type="text"
          placeholder={t('My Organization')}
          value={name}
          onChange={(event) => setName(event.target.value)}
          disabled={createMutation.isPending}
          maxLength={128}
          required
        />
        <p className="text-[13px] text-muted-foreground">
          {t('This becomes the client organization billed through your partner account.')}
        </p>
      </div>

      <div>
        <Label className="mb-2 block">
          {t('Select plan')} <span className="text-destructive">*</span>
        </Label>
        <p className="mb-4 text-[13px] text-muted-foreground">
          {t('Client organizations use Pro or Scale. Billing stays on this partner.')}
        </p>
        {plansLoading && catalogEntries.length === 0 ? (
          <div className="overflow-hidden rounded-xl border border-border bg-card/50">
            <div className="px-6 py-4">
              <p className="text-[13px] text-muted-foreground">
                {t('Loading plans...')}
              </p>
            </div>
          </div>
        ) : (
          <RadioGroup
            value={selectedPlan}
            onValueChange={setSelectedPlan}
            className="grid gap-3"
          >
            {planEntries.map(([planId, plan]) => {
              const planName = resolveOrganizationPlanDisplayLabel({
                billingPlan: planId,
                planName: plan.name,
                planId: plan.$id,
              })
              const isSelected = selectedPlan === planId
              const isRecommended =
                getPlanCanonicalFromRecord(planId, catalog) === 'pro'
              const description = plan.desc || plan.description
              const price = plan.price || 0

              return (
                <div
                  key={planId}
                  role="radio"
                  aria-checked={isSelected}
                  tabIndex={0}
                  onClick={() => setSelectedPlan(planId)}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                      event.preventDefault()
                      setSelectedPlan(planId)
                    }
                  }}
                  className={cn(
                    'flex cursor-pointer items-start gap-4 rounded-lg border p-4 transition-colors',
                    isSelected
                      ? 'border-primary bg-card'
                      : 'border-border bg-card/50 hover:border-primary/30',
                  )}
                >
                  <RadioGroupItem
                    value={planId}
                    id={`client-plan-${planId}`}
                    className="pointer-events-none mt-0.5 shrink-0"
                  />
                  <Label
                    htmlFor={`client-plan-${planId}`}
                    className="min-w-0 flex-1 pointer-events-none cursor-pointer"
                  >
                    <div className="space-y-1.5">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="text-[15px] font-semibold text-foreground">
                          {planName}
                        </span>
                        {isRecommended ? (
                          <Badge
                            variant="success"
                            className="h-5 shrink-0 px-2 py-0.5 text-[10px] font-medium"
                          >
                            {t('Recommended')}
                          </Badge>
                        ) : null}
                      </div>
                      {description ? (
                        <p className="text-[13px] leading-snug text-muted-foreground">
                          {description}
                        </p>
                      ) : null}
                      {price > 0 ? (
                        <div className="text-[13px] font-medium text-foreground">
                          ${price.toFixed(2)} {t('per month')}
                        </div>
                      ) : null}
                    </div>
                  </Label>
                </div>
              )
            })}
          </RadioGroup>
        )}
      </div>

      <div className="overflow-hidden rounded-xl border border-border bg-card/50">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Payment method')} <span className="text-destructive">*</span>
          </h3>
          <p className="mt-2 text-[13px] text-muted-foreground">
            {t('Invoices for this client use the selected payment method.')}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <PaymentMethodDropdown
            paymentMethods={paymentMethods}
            selectedPaymentMethodId={paymentMethodId}
            onPaymentMethodSelect={setPaymentMethodId}
            onAddPaymentMethod={() => setPaymentModalOpen(true)}
          />
        </div>
      </div>

      <div className="overflow-hidden rounded-xl border border-border bg-card/50">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Handoff resources')}
          </h3>
          <p className="mt-2 text-[13px] text-muted-foreground">
            {t(
              'Move development projects, apps, or domains from this partner organization into the new client. Skip this if you will add them later.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <HandoffResourcePicker
            partnerId={partnerId}
            selected={handoffItems}
            onSelectedChange={setHandoffItems}
            disabled={createMutation.isPending}
          />
        </div>
      </div>

      <PaymentModal
        open={paymentModalOpen}
        onOpenChange={setPaymentModalOpen}
        organizationId={partnerId}
        onSuccess={async () => {
          setPaymentModalOpen(false)
          const result = await queryClient.fetchQuery(
            paymentMethodsQueryOptions(),
          )
          const newest = [...(result.paymentMethods ?? [])]
            .filter((method) => method.last4)
            .sort(
              (a, b) =>
                new Date(b.$createdAt).getTime() -
                new Date(a.$createdAt).getTime(),
            )[0]
          if (newest) setPaymentMethodId(newest.$id)
        }}
        elevatedForWizard
      />
    </WizardLayout>
  )
}

function OnboardSummary({
  planLabel,
  planPrice = 0,
  additionalProjectsCount,
  additionalProjectsCost,
  estimatedMonthlyTotal,
  budgetEnabled,
  budgetValue,
  onBudgetEnabledChange,
  onBudgetValueChange,
  handoffCounts,
  partnerName,
  hasPaymentMethod,
}: {
  planLabel: string
  planPrice?: number
  additionalProjectsCount: number
  additionalProjectsCost: number
  estimatedMonthlyTotal: number
  budgetEnabled: boolean
  budgetValue: string
  onBudgetEnabledChange: (enabled: boolean) => void
  onBudgetValueChange: (value: string) => void
  handoffCounts: { project: number; domain: number; app: number }
  partnerName?: string
  hasPaymentMethod: boolean
}) {
  const t = useT()
  const billedTo = partnerName || t('This partner')
  const handoffLines = [
    { label: 'projects', count: handoffCounts.project },
    { label: 'apps', count: handoffCounts.app },
    { label: 'domains', count: handoffCounts.domain },
  ].filter((line) => line.count > 0)

  if (!hasPaymentMethod) {
    return (
      <div className="overflow-hidden rounded-xl border border-border bg-card/50 sticky top-6">
        <div className="px-6 py-4">
          <h3 className="text-[13px] font-semibold text-foreground">
            {t('Estimated total')}
          </h3>
          <p className="mt-2 text-[13px] leading-relaxed text-muted-foreground">
            {t('Add a payment method to see your estimated total.')}
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/50 sticky top-6">
      <div className="px-6 py-4">
        <h3 className="text-[13px] font-semibold text-foreground">
          {t('Estimated total')}
        </h3>
      </div>

      <div className="border-t border-border" />

      <div className="space-y-4 px-6 py-4">
        <div className="space-y-2">
          <div className="flex items-center justify-between text-[13px]">
            <span className="text-muted-foreground">
              {planLabel} {t('plan')}
            </span>
            <span className="font-medium text-foreground">
              {formatCurrency(planPrice)}
            </span>
          </div>
          {additionalProjectsCount > 0 ? (
            <div className="flex items-center justify-between text-[13px]">
              <span className="text-muted-foreground">
                {t('Additional projects')} ({additionalProjectsCount})
              </span>
              <span className="font-medium text-foreground">
                {formatCurrency(additionalProjectsCost)}
              </span>
            </div>
          ) : null}
        </div>

        <div className="space-y-2 border-t border-border pt-2">
          <div className="flex items-center justify-between text-[13px]">
            <span className="text-muted-foreground">{t('Handoff')}</span>
            <span className="font-medium text-foreground">
              {handoffLines.length === 0
                ? t('None')
                : handoffLines
                    .map((line) => `${line.count} ${t(line.label)}`)
                    .join(', ')}
            </span>
          </div>
          <div className="flex items-center justify-between gap-4 text-[13px]">
            <span className="text-muted-foreground">{t('Billed to')}</span>
            <span
              className="min-w-0 text-end font-medium text-foreground"
              title={billedTo}
            >
              {billedTo}
            </span>
          </div>
        </div>

        <div className="border-t border-border pt-2">
          <div className="flex items-center justify-between">
            <span className="text-[13px] font-medium text-foreground">
              {t('Total due now')}
            </span>
            <span className="text-[15px] font-semibold text-foreground">
              {formatCurrency(estimatedMonthlyTotal)}
            </span>
          </div>
        </div>

        {estimatedMonthlyTotal > 0 ? (
          <div className="pt-2">
            <div className="flex items-center justify-between">
              <span className="text-[13px] text-muted-foreground">
                {t('Recurring Charge')}
              </span>
              <span className="text-[13px] font-medium text-foreground">
                {formatCurrency(estimatedMonthlyTotal)}/{t('month')}
              </span>
            </div>
          </div>
        ) : null}

        <div className="space-y-3 border-t border-border pt-4">
          <div className="flex items-center justify-between">
            <Label htmlFor="client-budget-cap" className="text-[13px] font-medium">
              {t('Budget cap')}
            </Label>
            <Switch
              id="client-budget-cap"
              checked={budgetEnabled}
              onCheckedChange={onBudgetEnabledChange}
            />
          </div>
          {budgetEnabled ? (
            <div>
              <div className="relative">
                <span className="absolute start-3 top-1/2 -translate-y-1/2 text-[13px] text-muted-foreground">
                  $
                </span>
                <Input
                  id="client-budget-amount"
                  type="number"
                  placeholder="200"
                  value={budgetValue}
                  onChange={(event) => onBudgetValueChange(event.target.value)}
                  className="h-9 ps-7 text-[13px]"
                  min={1}
                  step={1}
                  inputMode="numeric"
                />
              </div>
              <p className="mt-1 text-[12px] text-muted-foreground">
                {t('Set a monthly spending limit')}
              </p>
            </div>
          ) : null}
        </div>
      </div>
    </div>
  )
}
