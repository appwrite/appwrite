import { useQuery } from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import { UpgradePlanLink } from '@/components/global/shared/UpgradePlanLink'
import { useOrganizationDomainsPlanLimit } from '@/components/pages/organizations/$orgId/domains/_components/useOrganizationDomainsPlanLimit'
import { useT } from '@/lib/i18n/translate'
import { organizationDomainsQueryOptions } from '@/lib/react-query/hooks'
import { useConsoleVariables } from '@/lib/react-query/hooks/console-variables'
import {
  blocksNewOrganizationApex,
  getApexDomain,
  isAppwriteServedDomain,
  isCloud,
  isValidDomain,
} from '@/lib/utils/proxy-domains'

/**
 * Cloud domain cap for an Add domain wizard.
 *
 * The cap counts organization apexes. `ensureApexDomainInOrganization` only
 * POSTs when the apex is new, so a subdomain of an apex the organization
 * already has stays allowed. Self-hosted never reaches the check: the limit
 * is Cloud-only, and the same `isCloud()` gate skips the POST.
 */
export function useNewApexPlanLimit(
  teamId: string | null | undefined,
  domain: string,
  region?: string,
) {
  const cloud = isCloud()
  const { isAtLimit, limit } = useOrganizationDomainsPlanLimit(
    cloud ? teamId : undefined,
  )
  const { sitesDomain, functionsDomain } = useConsoleVariables(region)
  const extraZones = [sitesDomain ?? '', functionsDomain ?? '']
  const normalized = domain.trim().toLowerCase()
  const apex =
    cloud &&
    isAtLimit &&
    isValidDomain(normalized) &&
    !isAppwriteServedDomain(normalized, extraZones)
      ? getApexDomain(normalized)
      : null

  const apexQuery = useQuery({
    ...organizationDomainsQueryOptions(
      teamId,
      0,
      1,
      undefined,
      apex ? [Query.equal('domain', apex)] : undefined,
    ),
    enabled: !!teamId && !!apex,
  })
  const settled = apexQuery.isSuccess && !apexQuery.isPlaceholderData
  const listed = apexQuery.data?.domains ?? []
  const named = listed.some((item) => item.domain?.toLowerCase() === apex)
  const apexRegistered = !apex || !settled ? null : named

  return {
    blocked: blocksNewOrganizationApex({
      cloud,
      atLimit: isAtLimit,
      domain,
      extraZones,
      apexRegistered,
    }),
    limit,
  }
}

/** Same inline cap copy and upgrade link the organization domain wizards use. */
export function DomainPlanLimitNotice({
  orgId,
  limit,
}: {
  orgId: string | null | undefined
  limit: number
}) {
  const t = useT()

  return (
    <p className="mt-2 text-[12px] text-amber-600 dark:text-amber-400">
      {t('Your current plan includes up to')} {limit} {t('domains')}.{' '}
      <UpgradePlanLink orgId={orgId} /> {t('to register another domain.')}
    </p>
  )
}
