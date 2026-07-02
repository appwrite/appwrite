/**
 * Buy Domain Wizard
 *
 * Full-screen wizard for buying a domain. Renders the configured TLD list and
 * fetches prices only for cards that enter the viewport (initial batch + scroll).
 */

import { useEffect, useRef, useState } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { DomainPurchaseStatus } from '@appwrite.io/console'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { Button } from '@/components/ui/button'
import { toast } from 'sonner'
import {
  finalizeDomainPurchase,
  fetchDomainPrice,
} from '@/lib/react-query/hooks/domains'
import { BuyDomainCheckout, type BuyDomainSelection } from './BuyDomainCheckout'
import type { BuyDomainWizardSearch } from '@/routes/_public/organizations.$orgId.domains.buy'
import { UpgradePlanLink } from '@/components/global/shared/UpgradePlanLink'
import { useOrganizationDomainsPlanLimit } from './useOrganizationDomainsPlanLimit'
import { DomainSearchResults } from './DomainSearchResults'
import type { DomainSelectionQuote } from '@/lib/domains/search'
import { useT } from '@/lib/i18n/translate'

export function BuyDomainWizard({
  routeSearch,
}: {
  routeSearch: BuyDomainWizardSearch
}) {
  const t = useT()
  const { orgId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [stage, setStage] = useState<'search' | 'checkout'>('search')
  const [checkoutSelection, setCheckoutSelection] =
    useState<BuyDomainSelection | null>(null)
  const paymentReturnHandled = useRef(false)
  const deepLinkHandled = useRef(false)
  const { isAtLimit: isDomainLimitReached, limit: domainsLimit } =
    useOrganizationDomainsPlanLimit(orgId)

  const fallbackPath = `/organizations/${orgId}/domains/`
  const initialSearch = routeSearch.domain ?? ''

  useEffect(() => {
    if (
      routeSearch.payment !== 'purchase' ||
      !routeSearch.invoiceId ||
      !orgId ||
      paymentReturnHandled.current
    ) {
      return
    }
    paymentReturnHandled.current = true
    ;(async () => {
      try {
        const result = await finalizeDomainPurchase({
          invoiceId: routeSearch.invoiceId!,
          organizationId: orgId,
        })
        if (result.status === DomainPurchaseStatus.Succeeded) {
          await queryClient.refetchQueries({
            queryKey: ['domains', 'organization', orgId],
          })
          toast.success(t('Payment confirmed'))
          navigate({
            to: '/organizations/$orgId/domains/$domainId',
            params: { orgId, domainId: result.domainId },
            replace: true,
          })
        } else {
          toast.error(t('Purchase could not be completed'))
          navigate({
            to: '/organizations/$orgId/domains/buy',
            params: { orgId },
            search: {},
            replace: true,
          })
        }
      } catch (e) {
        toast.error(
          e instanceof Error ? e.message : t('Failed to complete purchase'),
        )
        navigate({
          to: '/organizations/$orgId/domains/buy',
          params: { orgId },
          search: {},
          replace: true,
        })
      }
    })()
  }, [
    routeSearch.payment,
    routeSearch.invoiceId,
    orgId,
    navigate,
    queryClient,
    t,
  ])

  useEffect(() => {
    if (
      deepLinkHandled.current ||
      routeSearch.stage !== 'checkout' ||
      !routeSearch.domain ||
      !orgId
    ) {
      return
    }

    deepLinkHandled.current = true
    const domain = routeSearch.domain.trim().toLowerCase()

    ;(async () => {
      try {
        const quote = await fetchDomainPrice(domain)
        if (!quote.available) {
          toast.error(`${domain} ${t('is not available')}`)
          return
        }
        if (isDomainLimitReached) {
          toast.error(
            `${t('Your current plan includes up to')} ${domainsLimit} ${t('domains')}.`,
          )
          return
        }

        setCheckoutSelection({
          domain,
          price: quote.price,
          periodYears:
            typeof quote.periodYears === 'number' ? quote.periodYears : 1,
          premium: quote.premium,
          renewalPrice: quote.renewalPrice,
          renewalPeriodYears: quote.renewalPeriodYears,
        })
        setStage('checkout')
      } catch (e) {
        toast.error(
          e instanceof Error ? e.message : t('Failed to load domain price'),
        )
      }
    })()
  }, [
    routeSearch.domain,
    routeSearch.stage,
    orgId,
    isDomainLimitReached,
    domainsLimit,
    t,
  ])

  const handleSelectDomain = (
    full: string,
    opts?: DomainSelectionQuote,
  ) => {
    if (isDomainLimitReached) {
      toast.error(
        `${t('Your current plan includes up to')} ${domainsLimit} ${t('domains')}.`,
      )
      return
    }
    setCheckoutSelection({
      domain: full.toLowerCase(),
      price: opts?.price,
      periodYears: opts?.periodYears ?? 1,
      premium: opts?.premium,
      renewalPrice: opts?.renewalPrice,
      renewalPeriodYears: opts?.renewalPeriodYears,
    })
    setStage('checkout')
  }

  if (stage === 'checkout' && checkoutSelection && orgId) {
    return (
      <BuyDomainCheckout
        orgId={orgId}
        selection={checkoutSelection}
        fallbackPath={fallbackPath}
        onBackToSearch={() => {
          setStage('search')
          setCheckoutSelection(null)
        }}
      />
    )
  }

  return (
    <WizardLayout
      title={t('Buy domain')}
      fallbackPath={fallbackPath}
      fullscreen
      useSidebar={false}
      footer={
        <div className="flex gap-2 justify-end w-full">
          <Button variant="outline" onClick={() => navigate({ to: '..' })}>
            {t('Cancel')}
          </Button>
        </div>
      }
    >
      <DomainSearchResults
        initialSearch={initialSearch}
        onSelectDomain={handleSelectDomain}
        limitReached={isDomainLimitReached}
        limitMessage={
          isDomainLimitReached ? (
            <p className="text-[12px] text-amber-600 dark:text-amber-400">
              {t('Your current plan includes up to')} {domainsLimit}{' '}
              {t('domains')}. <UpgradePlanLink orgId={orgId} />{' '}
              {t('to buy another domain.')}
            </p>
          ) : null
        }
      />
    </WizardLayout>
  )
}
