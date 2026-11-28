import { useCallback, useMemo } from 'react'
import { useSiteDeployments, useProjectSite } from '@/lib/react-query/hooks'
import { RuntimeLogsView } from '@/components/global/shared/RuntimeLogsView'
import {
  runtimeLogFiltersFromSearch,
  runtimeLogsSearchFromFilters,
  type RuntimeLogFilters,
} from '@/lib/runtime-log-filters'
import { Route } from '@/routes/_public/projects.$projectId.sites.$siteId.runtime-logs'
import { DeploymentSelect } from '../shared/DeploymentSelect'

/** Newest deployments offered in the picker. */
const DEPLOYMENTS_LIMIT = 100

export function View() {
  const { projectId, siteId } = Route.useParams()
  const search = Route.useSearch()
  const navigate = Route.useNavigate()

  const { data: site } = useProjectSite(projectId, siteId)
  const { deployments, isLoading } = useSiteDeployments(
    projectId,
    siteId,
    0,
    DEPLOYMENTS_LIMIT,
  )

  const deploymentId =
    search.deploymentId || site?.deploymentId || deployments[0]?.$id

  const handleDeploymentChange = (value: string) => {
    void navigate({
      search: (prev) => ({ ...prev, deploymentId: value }),
      replace: true,
    })
  }

  // Filters live in the URL beside the deployment
  const filters = useMemo(() => runtimeLogFiltersFromSearch(search), [search])
  const handleFiltersChange = useCallback(
    (next: RuntimeLogFilters) => {
      void navigate({
        search: (prev) => ({ ...prev, ...runtimeLogsSearchFromFilters(next) }),
        replace: true,
      })
    },
    [navigate],
  )

  return (
    <div className="flex min-h-0 min-w-0 flex-1 flex-col">
      <RuntimeLogsView
        projectId={projectId}
        resourceType="sites"
        resourceId={siteId}
        deploymentId={deploymentId}
        filters={filters}
        onFiltersChange={handleFiltersChange}
        toolbarStart={
          <DeploymentSelect
            deployments={deployments}
            activeDeploymentId={site?.deploymentId}
            value={deploymentId}
            onChange={handleDeploymentChange}
            disabled={isLoading && !deploymentId}
          />
        }
      />
    </div>
  )
}
