import { useParams } from '@tanstack/react-router'
import {
  useProjectFunction,
  useFunctionDeployments,
  useFunctionSpecifications,
} from '@/lib/react-query/hooks'
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
import { ActiveFunctionDeploymentCard } from './_components/ActiveFunctionDeploymentCard'

function formatRuntimeLabel(runtime: string | undefined) {
  if (!runtime?.trim()) return undefined
  return runtime.split('-').join(' ')
}

export function View() {
  const t = useT()
  const { projectId, functionId } = useParams({ strict: false })
  const { data: func, isLoading: functionLoading } = useProjectFunction(
    projectId,
    functionId,
  )
  const {
    deployments,
    total,
    isLoading: deploymentsLoading,
  } = useFunctionDeployments(
    projectId,
    functionId,
    0,
    OVERVIEW_DEPLOYMENTS_LIMIT,
  )
  const { data: specificationsData, isLoading: specificationsLoading } =
    useFunctionSpecifications(projectId, SpecificationType.Runtimes)
  const lastDeployed = func ? getActiveDeploymentCreatedAt(func) : undefined
  const runtimeLabel = formatRuntimeLabel(func?.runtime)
  const specificationLabel = formatSpecificationLabel(
    specificationsData?.specifications?.find(
      (spec) => spec.slug === func?.runtimeSpecification,
    ),
  )

  if (functionLoading || !projectId || !functionId) {
    return (
      <div className="flex h-full items-center justify-center">
        <p className="text-muted-foreground">{t('Loading function...')}</p>
      </div>
    )
  }

  if (!func) {
    return (
      <div className="flex h-full items-center justify-center">
        <p className="text-muted-foreground">{t('Function not found')}</p>
      </div>
    )
  }

  return (
    <div className="flex-1">
      <div className="mx-auto w-full max-w-7xl space-y-6 px-4 pb-4 pt-4 sm:px-6 sm:pb-6 sm:pt-6">
        <OverviewStatTiles
          items={[
            {
              label: t('Runtime'),
              value: runtimeLabel ? t(runtimeLabel) : '—',
            },
            {
              label: t('Specification'),
              value: specificationLabel ?? '—',
              loading: specificationsLoading && !specificationLabel,
            },
            {
              label: t('Logging'),
              value: func.logging === false ? t('Off') : t('On'),
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

        <ActiveFunctionDeploymentCard
          projectId={projectId}
          functionId={functionId}
        />

        <RecentDeploymentsCard
          kind="function"
          projectId={projectId}
          resourceId={functionId}
          deployments={deployments}
          total={total}
          activeDeploymentId={func.deploymentId}
        />

        <ResourceUsageCards
          projectId={projectId}
          resourceId={functionId}
          resourceType="function"
        />

        <ResourceFirewallCard
          projectId={projectId}
          resourceType="functions"
          resourceId={functionId}
        />
      </div>
    </div>
  )
}
