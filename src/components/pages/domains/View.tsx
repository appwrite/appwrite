import { useCallback } from 'react'
import { Link, useNavigate } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { DomainsSearchBackground } from '@/components/pages/domains/DomainsSearchSoftLights'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { DomainSearchResults } from '@/components/pages/organizations/$orgId/domains/_components/DomainSearchResults'
import {
  buildBuyDomainWizardSearch,
  buildSignInForDomainPath,
  buildSignUpForDomainPath,
} from '@/lib/domains/buy-wizard'
import type { DomainSelectionQuote } from '@/lib/domains/search'
import { domainsHero } from '@/lib/domains/marketing-content'
import { ensurePersonalOrgAndFirstProject } from '@/lib/ensure-personal-org'
import { trackEvent } from '@/lib/analytics'
import { useT } from '@/lib/i18n/translate'

type DomainsViewProps = {
  initialSearch?: string
}

function DomainsAuthFooter() {
  const t = useT()
  return (
    <p>
      <Link
        {...buildSignUpForDomainPath()}
        className="link-neutral"
      >
        {t('Create an account')}
      </Link>{' '}
      {t('or')}{' '}
      <Link
        {...buildSignInForDomainPath()}
        className="link-neutral"
      >
        {t('sign in')}
      </Link>{' '}
      {t('to register and connect your domain.')}
    </p>
  )
}

export function View({ initialSearch = '' }: DomainsViewProps) {
  const t = useT()
  const navigate = useNavigate()
  const {
    account: accountUnknown,
    isAuthenticated,
    isLoading,
    isFetched,
  } = useAuth()
  const account = accountUnknown as Models.User | undefined
  const authReady = isFetched && !isLoading
  const showAuthFooter = authReady && !isAuthenticated

  const continueToBuyWizard = useCallback(
    async (domain: string) => {
      const search = buildBuyDomainWizardSearch({
        domain,
        stage: 'checkout',
      })

      if (!isAuthenticated || !account) {
        navigate(buildSignUpForDomainPath(search))
        return
      }

      const orgId = account.prefs?.organization as string | undefined
      if (orgId) {
        navigate({
          to: '/organizations/$orgId/domains/buy',
          params: { orgId },
          search,
        })
        return
      }

      try {
        const resolvedOrgId = await ensurePersonalOrgAndFirstProject()
        navigate({
          to: '/organizations/$orgId/domains/buy',
          params: { orgId: resolvedOrgId },
          search,
        })
      } catch {
        navigate({
          to: '/domains/continue',
          search,
        })
      }
    },
    [account, isAuthenticated, navigate],
  )

  const handleSelectDomain = useCallback(
    (full: string, _quote: DomainSelectionQuote) => {
      trackEvent('Wizard Option Selected', {
        wizard: 'domains_marketing',
        step: 'search',
        result: 'domain_selected',
      })
      void continueToBuyWizard(full)
    },
    [continueToBuyWizard],
  )

  return (
    <div className="relative isolate min-h-[calc(100dvh-3.5rem)] min-w-0 bg-background">
      <DomainsSearchBackground />
      <div className="relative z-[2]">
        <DomainSearchResults
          variant="focus"
          initialSearch={initialSearch}
          onSelectDomain={handleSelectDomain}
          actionLabel={t('Continue')}
          inputId="marketing-domain-search"
          title={t(domainsHero.title)}
          description={t(domainsHero.description)}
          footer={showAuthFooter ? <DomainsAuthFooter /> : undefined}
        />
      </div>
    </div>
  )
}
