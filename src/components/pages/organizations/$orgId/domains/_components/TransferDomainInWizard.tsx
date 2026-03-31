import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from '@tanstack/react-router'
import { DomainPurchaseStatus } from '@appwrite.io/console'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { PaymentMethodDropdown } from '@/components/pages/organizations/$orgId/billing/change-plan/PaymentMethodDropdown'
import { PaymentModal } from '@/components/pages/organizations/$orgId/billing/Payment'
import { useOrganizationById, usePaymentMethods } from '@/lib/react-query/hooks'
import {
  createDomainTransferIn,
  domainTransferPriceQueryOptions,
  finalizeDomainTransferIn,
} from '@/lib/react-query/hooks/domains'
import { confirmPayment } from '@/lib/utils/stripe'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { toast } from 'sonner'
import type { TransferInSearch } from '@/routes/_public/organizations.$orgId.domains.transfer-in'
import { TransferDomainInSummary } from './TransferDomainInSummary'

const PRICE_DEBOUNCE_MS = 500

export function TransferDomainInWizard({
  search,
}: {
  search: TransferInSearch
}) {
  const { orgId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { organization } = useOrganizationById(orgId)
  const { paymentMethods } = usePaymentMethods()

  const [domainInput, setDomainInput] = useState('')
  const [authCode, setAuthCode] = useState('')
  const [paymentMethodId, setPaymentMethodId] = useState('')
  const [paymentModalOpen, setPaymentModalOpen] = useState(false)
  const [debouncedPriceDomain, setDebouncedPriceDomain] = useState('')
  const paymentReturnHandled = useRef(false)

  const fallbackPath = `/organizations/${orgId}/domains/`

  useEffect(() => {
    const raw = domainInput.trim().toLowerCase()
    if (!raw.includes('.')) {
      setDebouncedPriceDomain('')
      return
    }
    const t = setTimeout(() => setDebouncedPriceDomain(raw), PRICE_DEBOUNCE_MS)
    return () => clearTimeout(t)
  }, [domainInput])

  const priceQuery = useQuery(
    domainTransferPriceQueryOptions(debouncedPriceDomain),
  )

  useEffect(() => {
    if (!organization) return
    const pm =
      organization.paymentMethodId || organization.backupPaymentMethodId
    if (pm) {
      setPaymentMethodId((id) => id || pm)
    }
  }, [organization])

  useEffect(() => {
    if (
      search.payment !== 'transfer_in' ||
      !search.domainId ||
      !orgId ||
      paymentReturnHandled.current
    ) {
      return
    }
    paymentReturnHandled.current = true
    ;(async () => {
      try {
        const result = await finalizeDomainTransferIn({
          domainId: search.domainId!,
          organizationId: orgId,
        })
        if (result.status === DomainPurchaseStatus.Succeeded) {
          await queryClient.refetchQueries({
            queryKey: ['domains', 'organization', orgId],
          })
          toast.success('Transfer payment confirmed')
          navigate({
            to: '/organizations/$orgId/domains/$domainId',
            params: { orgId, domainId: search.domainId! },
            replace: true,
          })
        } else {
          toast.error('Transfer could not be completed')
          navigate({
            to: '/organizations/$orgId/domains/transfer-in',
            params: { orgId },
            search: {},
            replace: true,
          })
        }
      } catch (e) {
        toast.error(
          e instanceof Error ? e.message : 'Failed to complete transfer',
        )
        navigate({
          to: '/organizations/$orgId/domains/transfer-in',
          params: { orgId },
          search: {},
          replace: true,
        })
      }
    })()
  }, [search.payment, search.domainId, orgId, navigate, queryClient])

  const completedPaymentMethods = useMemo(
    () => paymentMethods.filter((pm) => pm.last4),
    [paymentMethods],
  )

  const transferMutation = useMutation({
    mutationFn: async () => {
      if (!orgId) throw new Error('Organization is required')
      const domain = domainInput.trim().toLowerCase()
      if (!domain || !domain.includes('.')) {
        throw new Error('Enter a full domain name (e.g. example.com)')
      }
      if (!authCode.trim()) {
        throw new Error('Authorization code is required')
      }
      if (!paymentMethodId) {
        throw new Error('Select a payment method')
      }

      const purchase = await createDomainTransferIn({
        domain,
        organizationId: orgId,
        authCode: authCode.trim(),
        paymentMethodId,
      })

      if (purchase.status === DomainPurchaseStatus.Succeeded) {
        return { kind: 'done' as const, domainId: purchase.domainId }
      }

      if (purchase.clientSecret) {
        await confirmPayment({
          clientSecret: purchase.clientSecret,
        })
      }

      const finalized = await finalizeDomainTransferIn({
        domainId: purchase.domainId,
        organizationId: orgId,
      })
      if (finalized.status !== DomainPurchaseStatus.Succeeded) {
        throw new Error('Transfer could not be completed. Please try again.')
      }
      return { kind: 'done' as const, domainId: finalized.domainId }
    },
    onSuccess: async (result) => {
      await queryClient.refetchQueries({
        queryKey: ['domains', 'organization', orgId],
      })
      toast.success('Domain transfer started')
      navigate({
        to: '/organizations/$orgId/domains/$domainId',
        params: { orgId: orgId!, domainId: result.domainId },
      })
    },
    onError: (e: Error) => {
      toast.error(getErrorMessage(e))
    },
  })

  const canStartTransfer =
    !transferMutation.isPending &&
    completedPaymentMethods.length > 0 &&
    !!paymentMethodId &&
    domainInput.trim().includes('.') &&
    authCode.trim().length > 0

  if (!orgId) return null

  return (
    <WizardLayout
      title="Transfer domain in"
      fallbackPath={fallbackPath}
      fullscreen
      useSidebar
      footerAlign="right"
      sidebar={
        <TransferDomainInSummary
          quotedDomain={debouncedPriceDomain}
          isPriceLoading={priceQuery.isFetching && !!debouncedPriceDomain}
          priceError={!!priceQuery.isError}
          quote={priceQuery.data}
        />
      }
      footer={
        <>
          <Button
            variant="outline"
            type="button"
            disabled={transferMutation.isPending}
            onClick={() =>
              navigate({
                to: '/organizations/$orgId/domains',
                params: { orgId },
              })
            }
          >
            Cancel
          </Button>
          <Button
            type="button"
            disabled={!canStartTransfer}
            onClick={() => transferMutation.mutate()}
          >
            Start transfer
          </Button>
        </>
      }
    >
      <div className="w-full min-w-0 space-y-6 lg:max-w-none">
        <div>
          <h2 className="text-[15px] font-semibold text-foreground">
            Transfer an existing domain
          </h2>
          <p className="mt-1 text-[13px] text-muted-foreground">
            Request a transfer into this organization using the authorization
            code from your current registrar. Registry fees are shown in the
            summary as you type the domain name.
          </p>
        </div>

        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              Domain
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              Full hostname and the auth code your registrar provided.
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4 space-y-4">
            <div className="space-y-1.5">
              <Label htmlFor="td-domain" className="text-[13px]">
                Domain name
              </Label>
              <Input
                id="td-domain"
                value={domainInput}
                onChange={(e) => setDomainInput(e.target.value)}
                placeholder="example.com"
                className="h-9 text-[13px] font-mono"
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="td-auth" className="text-[13px]">
                Authorization code
              </Label>
              <Input
                id="td-auth"
                value={authCode}
                onChange={(e) => setAuthCode(e.target.value)}
                placeholder="From your current registrar"
                className="h-9 text-[13px] font-mono"
              />
            </div>
          </div>
        </div>

        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              Payment
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              Transfer fees are charged to the selected payment method.
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
      </div>

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
