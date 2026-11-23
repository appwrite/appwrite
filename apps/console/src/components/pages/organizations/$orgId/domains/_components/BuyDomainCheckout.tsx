import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { DomainPurchaseStatus } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { Plus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { PaymentMethodDropdown } from '@/components/pages/organizations/$orgId/billing/change-plan/PaymentMethodDropdown'
import { PaymentModal } from '@/components/pages/organizations/$orgId/billing/Payment'
import { AddressModal } from '@/components/pages/account/Payments/Address'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { UpgradePlanLink } from '@/components/global/shared/UpgradePlanLink'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  useOrganizationById,
  usePaymentMethods,
  useBillingAddresses,
} from '@/lib/react-query/hooks'
import {
  createDomainPurchase,
  finalizeDomainPurchase,
} from '@/lib/react-query/hooks/domains'
import { confirmPayment } from '@/lib/utils/stripe'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import { useOrganizationDomainsPlanLimit } from './useOrganizationDomainsPlanLimit'
import { useT } from '@/lib/i18n/translate'

export type BuyDomainSelection = {
  domain: string
  price?: number
  periodYears: number
  premium?: boolean
  /** Renewal period price from getPrice(registrationType: renewal), same currency as registration */
  renewalPrice?: number
  renewalPeriodYears?: number
}

function splitAccountName(name: string): {
  firstName: string
  lastName: string
} {
  const t = name.trim()
  if (!t) return { firstName: '', lastName: '' }
  const i = t.indexOf(' ')
  if (i <= 0) return { firstName: t, lastName: '' }
  return {
    firstName: t.slice(0, i),
    lastName: t.slice(i + 1).trim(),
  }
}

function formatUsd(amount: number) {
  return amount.toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

function renewalPeriodSuffix(periodYears: number) {
  if (periodYears <= 1) return '/yr'
  return `/${periodYears} yrs`
}

/** Right-column order summary for the buy-domain checkout wizard. */
export function BuyDomainPurchaseSummary({
  selection,
}: {
  selection: BuyDomainSelection
}) {
  const t = useT()
  const hasRegistrationPrice = selection.price != null && selection.price > 0
  const periodLabel =
    selection.periodYears === 1
      ? t('1 year')
      : `${selection.periodYears} ${t('years')}`
  const renewalYears =
    selection.renewalPeriodYears ?? selection.periodYears ?? 1
  const hasRenewal =
    selection.renewalPrice != null && selection.renewalPrice > 0

  return (
    <div className="rounded-xl border border-border bg-card overflow-hidden">
      <div className="border-b border-border bg-muted/30 px-5 py-3.5">
        <h3 className="text-[13px] font-semibold tracking-tight text-foreground">
          {t('Order summary')}
        </h3>
        <p className="mt-0.5 text-[12px] text-muted-foreground leading-snug">
          {t('Review charges before you complete payment.')}
        </p>
      </div>

      <div className="px-5 py-5">
        <p className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">
          {t('Domain')}
        </p>
        <div className="mt-2 flex flex-wrap items-center gap-2">
          <p className="min-w-0 break-all font-mono text-[15px] font-medium leading-snug tracking-tight text-foreground">
            {selection.domain}
          </p>
          {selection.premium ? (
            <Badge
              variant="info"
              className="shrink-0 px-1.5 py-0 text-[10px] font-medium"
            >
              {t('Premium')}
            </Badge>
          ) : null}
        </div>

        <div className="mt-6 space-y-3 border-t border-border pt-5">
          {hasRegistrationPrice ? (
            <>
              <div className="flex items-start justify-between gap-4 text-[13px] leading-snug">
                <div className="min-w-0">
                  <p className="font-medium text-foreground">
                    {t('Registration')}
                  </p>
                  <p className="mt-0.5 text-[12px] text-muted-foreground">
                    {t('Initial term')} · {periodLabel}
                  </p>
                </div>
                <p className="shrink-0 tabular-nums font-semibold text-foreground">
                  ${formatUsd(selection.price!)}
                </p>
              </div>
              {hasRenewal ? (
                <div className="flex items-start justify-between gap-4 text-[13px] leading-snug">
                  <div className="min-w-0">
                    <p className="font-medium text-foreground">
                      {t('Renewal')}
                    </p>
                    <p className="mt-0.5 text-[12px] text-muted-foreground">
                      {t('When you renew after the initial term')}
                    </p>
                  </div>
                  <p className="shrink-0 tabular-nums font-semibold text-foreground">
                    ${formatUsd(selection.renewalPrice!)}
                    <span className="ms-1 text-[12px] font-normal text-muted-foreground">
                      {renewalPeriodSuffix(renewalYears)}
                    </span>
                  </p>
                </div>
              ) : null}
            </>
          ) : (
            <p className="text-[13px] leading-relaxed text-muted-foreground">
              {t(
                'Pricing is confirmed when you submit payment. Premium and specialty names may require manual review from the registry.',
              )}
            </p>
          )}
        </div>

        {hasRegistrationPrice ? (
          <div className="mt-6 flex items-baseline justify-between gap-4 border-t border-border pt-5">
            <p className="text-[13px] font-semibold text-foreground">
              {t('Total due today')}
            </p>
            <p className="text-[22px] font-semibold tabular-nums tracking-tight text-foreground">
              ${formatUsd(selection.price!)}
            </p>
          </div>
        ) : null}
      </div>
    </div>
  )
}

type BuyDomainCheckoutProps = {
  orgId: string
  selection: BuyDomainSelection
  /** Close / navigate away target for the wizard header. */
  fallbackPath: string
  /** Return to domain search (stage 1). */
  onBackToSearch: () => void
}

export function BuyDomainCheckout({
  orgId,
  selection,
  fallbackPath,
  onBackToSearch,
}: BuyDomainCheckoutProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { account } = useAuth()
  const { organization } = useOrganizationById(orgId)
  const { paymentMethods } = usePaymentMethods()
  const { addresses } = useBillingAddresses()
  const { isAtLimit: isDomainLimitReached, limit: domainsLimit } =
    useOrganizationDomainsPlanLimit(orgId)

  const [firstName, setFirstName] = useState('')
  const [lastName, setLastName] = useState('')
  const [email, setEmail] = useState('')
  const [phone, setPhone] = useState('')
  const [companyName, setCompanyName] = useState('')
  const [billingAddressId, setBillingAddressId] = useState('')
  const [paymentMethodId, setPaymentMethodId] = useState('')
  const [paymentModalOpen, setPaymentModalOpen] = useState(false)
  const [addressModalOpen, setAddressModalOpen] = useState(false)

  const completedPaymentMethods = useMemo(
    () => paymentMethods.filter((pm) => pm.last4),
    [paymentMethods],
  )

  useEffect(() => {
    if (!account || typeof account !== 'object') return
    const acc = account as Models.User
    const { firstName: f, lastName: l } = splitAccountName(acc.name || '')
    setFirstName((prev) => prev || f)
    setLastName((prev) => prev || l)
    setEmail((prev) => prev || acc.email || '')
    setPhone((prev) => prev || acc.phone || '')
  }, [account])

  // Pick a valid billing address once addresses load (org default or first).
  useEffect(() => {
    if (addresses.length === 0) return
    setBillingAddressId((prev) => {
      if (prev && addresses.some((a) => a.$id === prev)) return prev
      const orgAddr = organization?.billingAddressId
      if (orgAddr && addresses.some((a) => a.$id === orgAddr)) return orgAddr
      return addresses[0].$id
    })
  }, [addresses, organization?.billingAddressId])

  // Pick a valid payment method once methods load (org default or first completed).
  useEffect(() => {
    if (completedPaymentMethods.length === 0) return
    setPaymentMethodId((prev) => {
      if (prev && completedPaymentMethods.some((p) => p.$id === prev))
        return prev
      const orgPm =
        organization?.paymentMethodId || organization?.backupPaymentMethodId
      if (orgPm && completedPaymentMethods.some((p) => p.$id === orgPm))
        return orgPm
      return completedPaymentMethods[0].$id
    })
  }, [
    completedPaymentMethods,
    organization?.paymentMethodId,
    organization?.backupPaymentMethodId,
  ])

  const purchaseMutation = useMutation({
    mutationFn: async () => {
      if (isDomainLimitReached) {
        throw new Error(
          `${t('Your current plan includes up to')} ${domainsLimit} ${t('domains')}.`,
        )
      }
      if (!billingAddressId) {
        throw new Error(t('Select a billing address'))
      }
      if (!paymentMethodId) {
        throw new Error(t('Select a payment method'))
      }
      const fn = firstName.trim()
      const ln = lastName.trim()
      if (!fn || !ln) {
        throw new Error(t('First and last name are required'))
      }
      if (!email.trim()) {
        throw new Error(t('Email is required'))
      }
      if (!phone.trim()) {
        throw new Error(t('Phone is required (E.164, e.g. +15551234567)'))
      }
      const purchase = await createDomainPurchase({
        domain: selection.domain,
        organizationId: orgId,
        firstName: fn,
        lastName: ln,
        email: email.trim(),
        phone: phone.trim(),
        billingAddressId,
        paymentMethodId,
        companyName: companyName.trim() || undefined,
        periodYears: selection.periodYears,
      })

      if (purchase.status === DomainPurchaseStatus.Succeeded) {
        return { kind: 'done' as const, domainId: purchase.domainId }
      }

      if (purchase.clientSecret) {
        // Backend already confirmed the PI; handle 3DS inline (no redirect)
        await confirmPayment({
          clientSecret: purchase.clientSecret,
        })
      }

      const finalized = await finalizeDomainPurchase({
        invoiceId: purchase.$id,
        organizationId: orgId,
      })
      if (finalized.status !== DomainPurchaseStatus.Succeeded) {
        throw new Error(
          t('Purchase could not be completed. Please try again.'),
        )
      }
      return { kind: 'done' as const, domainId: finalized.domainId }
    },
    onSuccess: async (result) => {
      await queryClient.refetchQueries({
        queryKey: ['domains', 'organization', orgId],
      })
      toast.success(`${selection.domain} ${t('registered successfully')}`)
      navigate({
        to: '/organizations/$orgId/domains/$domainId',
        params: { orgId, domainId: result.domainId },
      })
    },
    onError: (e: Error) => {
      toast.error(getErrorMessage(e))
    },
  })

  const addressLabel = (a: (typeof addresses)[0]) => {
    const parts = [
      a.streetAddress,
      a.addressLine2,
      a.city,
      a.state,
      a.postalCode,
      a.country,
    ]
      .filter(Boolean)
      .join(', ')
    return parts || a.$id
  }

  const canSubmit =
    !purchaseMutation.isPending &&
    !isDomainLimitReached &&
    !!billingAddressId &&
    !!paymentMethodId &&
    completedPaymentMethods.length > 0 &&
    addresses.length > 0

  return (
    <WizardLayout
      title={t('Buy domain')}
      fallbackPath={fallbackPath}
      fullscreen
      useSidebar
      sidebar={<BuyDomainPurchaseSummary selection={selection} />}
      showBackButton
      backButtonLabel={t('Back')}
      onBack={onBackToSearch}
      footerAlign="right"
      constrainFooterWidth
      footer={
        <>
          <Button variant="outline" type="button" onClick={onBackToSearch}>
            {t('Back')}
          </Button>
          <Button
            type="button"
            disabled={!canSubmit}
            className={cn(
              (completedPaymentMethods.length === 0 ||
                addresses.length === 0) &&
                'opacity-80',
            )}
            onClick={() => purchaseMutation.mutate()}
          >
            {t('Pay and register')}
          </Button>
        </>
      }
    >
      <div className="w-full min-w-0 space-y-6 lg:max-w-none">
        <div>
          <h2 className="text-[15px] font-semibold text-foreground">
            {t('Complete registration')}
          </h2>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {t('Registrant details must match your domain registry requirements.')}
          </p>
          {isDomainLimitReached ? (
            <p className="mt-2 text-[12px] text-amber-600 dark:text-amber-400">
              {t('Your current plan includes up to')} {domainsLimit}{' '}
              {t('domains')}. <UpgradePlanLink orgId={orgId} />{' '}
              {t('to register another domain.')}
            </p>
          ) : null}
        </div>

        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Registrant contact')}
            </h3>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4 space-y-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="space-y-1.5">
                <Label htmlFor="bd-first" className="text-[13px]">
                  {t('First name')}
                </Label>
                <Input
                  id="bd-first"
                  value={firstName}
                  onChange={(e) => setFirstName(e.target.value)}
                  className="h-9 text-[13px]"
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="bd-last" className="text-[13px]">
                  {t('Last name')}
                </Label>
                <Input
                  id="bd-last"
                  value={lastName}
                  onChange={(e) => setLastName(e.target.value)}
                  className="h-9 text-[13px]"
                />
              </div>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="bd-email" className="text-[13px]">
                {t('Email')}
              </Label>
              <Input
                id="bd-email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="h-9 text-[13px]"
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="bd-phone" className="text-[13px]">
                {t('Phone (E.164)')}
              </Label>
              <Input
                id="bd-phone"
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
                placeholder="+15551234567"
                className="h-9 text-[13px] font-mono"
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="bd-company" className="text-[13px]">
                {t('Company (optional)')}
              </Label>
              <Input
                id="bd-company"
                value={companyName}
                onChange={(e) => setCompanyName(e.target.value)}
                className="h-9 text-[13px]"
              />
            </div>
          </div>
        </div>

        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Billing address')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t('Used for registry contact and invoicing.')}
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4 space-y-3">
            {addresses.length > 0 ? (
              <Select
                value={billingAddressId || undefined}
                onValueChange={setBillingAddressId}
              >
                <SelectTrigger className="h-9 w-full min-w-0 text-[13px]">
                  <SelectValue placeholder={t('Select billing address')} />
                </SelectTrigger>
                <SelectContent>
                  {addresses.map((a) => (
                    <SelectItem key={a.$id} value={a.$id}>
                      {addressLabel(a)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            ) : (
              <div className="rounded-md border border-border bg-background px-3 py-2 text-[13px] text-muted-foreground">
                {t('No billing addresses on file.')}
              </div>
            )}
            <Button
              variant="ghost"
              size="sm"
              className="h-8 text-[13px]"
              onClick={() => setAddressModalOpen(true)}
            >
              <Plus className="me-1.5 h-4 w-4" />
              {t('Add billing address')}
            </Button>
          </div>
        </div>

        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Payment')}
            </h3>
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
      </div>

      <AddressModal
        open={addressModalOpen}
        onOpenChange={setAddressModalOpen}
        organizationId={orgId}
        elevatedForWizard
        onSuccess={(address) => {
          setAddressModalOpen(false)
          if (address?.$id) {
            setBillingAddressId(address.$id)
          }
        }}
      />

      <PaymentModal
        open={paymentModalOpen}
        onOpenChange={setPaymentModalOpen}
        organizationId={orgId}
        onSuccess={() => setPaymentModalOpen(false)}
        elevatedForWizard
      />
    </WizardLayout>
  )
}
