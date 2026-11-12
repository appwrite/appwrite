import { useMemo } from 'react'
import { useParams } from '@tanstack/react-router'
import { Query } from '@appwrite.io/console'
import {
  useProjectSite,
  useSiteDeployments,
  useSiteDomains,
  useSiteFrameworks,
  useSiteSpecifications,
} from '@/lib/react-query/hooks'
import { DOMAINS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { useT } from '@/lib/i18n/translate'
import {
  formatSpecificationLabel,
  SpecificationType,
} from '@/lib/specifications'
import { getActiveDeploymentCreatedAt } from '../shared/DeploymentResourceStatusBadges'
import { OverviewStatTiles } from '../shared/OverviewStatTiles'
import {
  OVERVIEW_DEPLOYMENTS_LIMIT,
  RecentDeploymentsCard,
} from '../shared/RecentDeploymentsCard'
import { ResourceFirewallCard } from '../shared/ResourceFirewallCard'
import { ResourceUsageCards } from '../shared/ResourceUsageCards'
import { ActiveSiteDeploymentCard } from './_components/ActiveSiteDeploymentCard'
import { SiteAnalyticsCard } from './_components/SiteAnalyticsCard'

const OVERVIEW_DEPLOYMENTS_SELECT = [
  Query.select([
    'status',
    'type',
    'resourceId',
    'buildSize',
    'sourceSize',
    'buildDuration',
    'providerRepositoryUrl',
    'providerRepositoryOwner',
    'providerRepositoryName',
    'providerBranchUrl',
    'providerBranch',
    'providerCommitMessage',
    'providerCommitHash',
    'providerCommitUrl',
    'providerCommitAuthor',
    'providerCommitAuthorUrl',
    '$createdAt',
  ]),
]

export function View() {
  const t = useT()
  const { projectId, siteId } = useParams({ strict: false })
  const { data: site, isLoading: siteLoading } = useProjectSite(
    projectId,
    siteId,
  )
  const {
    deployments,
    total,
    isLoading: deploymentsLoading,
  } = useSiteDeployments(
    projectId,
    siteId,
    0,
    OVERVIEW_DEPLOYMENTS_LIMIT,
    OVERVIEW_DEPLOYMENTS_SELECT,
  )
  const {
    rules: siteDomainsRules,
    isLoading: domainsLoading,
  } = useSiteDomains(projectId, siteId, 0, DOMAINS_DEFAULT_PAGE_SIZE, '')
  const { data: frameworksData } = useSiteFrameworks(projectId)
  const { data: specificationsData, isLoading: specificationsLoading } =
    useSiteSpecifications(projectId, SpecificationType.Runtimes)

  const siteDomainNames = useMemo(
    () => (siteDomainsRules ?? []).map((rule) => rule.domain),
    [siteDomainsRules],
  )
  const lastDeployed = site ? getActiveDeploymentCreatedAt(site) : undefined
  const frameworkName =
    frameworksData?.frameworks?.find((framework) => framework.key === site?.framework)
      ?.name ?? site?.framework
  const adapterLabel =
    site?.adapter === 'ssr'
      ? t('SSR')
      : site?.adapter === 'static'
        ? t('Static')
        : undefined
  const specificationLabel = formatSpecificationLabel(
    specificationsData?.specifications?.find(
      (spec) => spec.slug === site?.runtimeSpecification,
    ),
  )

  if (siteLoading || !projectId || !siteId) {
    return (
      <div className="flex h-full items-center justify-center">
        <p className="text-muted-foreground">{t('Loading site...')}</p>
      </div>
    )
  }

  return (
    <div className="flex-1">
      <div className="mx-auto w-full max-w-7xl space-y-6 px-4 pb-4 pt-4 sm:px-6 sm:pb-6 sm:pt-6">
        <OverviewStatTiles
          items={[
            {
              label: t('Framework'),
              value: frameworkName ? t(frameworkName) : '—',
            },
            {
              label: t('Adapter'),
              value: adapterLabel ?? '—',
            },
            {
              label: t('Specification'),
              value: specificationLabel ?? '—',
              loading: specificationsLoading && !specificationLabel,
            },
            {
              label: t('Deployments'),
              value: deploymentsLoading ? '—' : total.toLocaleString(),
            },
            {
              label: t('Last deployed'),
              value: lastDeployed ? (
                <DateTooltip
                  date={lastDeployed}
                  live
                  className="text-[20px] font-semibold text-foreground"
                />
              ) : (
                t('Never')
              ),
            },
          ]}
        />

        <ActiveSiteDeploymentCard projectId={projectId} siteId={siteId} />

        {site ? (
          <SiteAnalyticsCard
            projectId={projectId}
            siteName={site.name}
            siteDomains={siteDomainNames}
            domainsLoading={domainsLoading}
          />
        ) : null}

        <RecentDeploymentsCard
          kind="site"
          projectId={projectId}
          resourceId={siteId}
          deployments={deployments}
          total={total}
          activeDeploymentId={site?.deploymentId}
        />

        <ResourceUsageCards
          projectId={projectId}
          resourceId={siteId}
          resourceType="site"
        />

        <ResourceFirewallCard
          projectId={projectId}
          resourceType="sites"
          resourceId={siteId}
        />
      </div>
    </div>
  )
}
