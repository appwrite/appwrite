import { useNavigate } from '@tanstack/react-router'
import { useSiteDeployments, useProjectSite } from '@/lib/react-query/hooks'
import { RuntimeLogsView } from '@/components/global/shared/RuntimeLogsView'
import { Route } from '@/routes/_public/projects.$projectId.sites.$siteId.runtime-logs'
import { DeploymentSelect } from '../shared/DeploymentSelect'

export function View() {
  const { projectId, siteId } = Route.useParams()
  const search = Route.useSearch()
  const navigate = useNavigate()

  const { data: site } = useProjectSite(projectId, siteId)
  const { deployments, isLoading } = useSiteDeployments(projectId, siteId)

  const deploymentId =
    search.deploymentId || site?.deploymentId || deployments[0]?.$id

  const handleDeploymentChange = (value: string) => {
    navigate({
      to: '.',
      search: { deploymentId: value },
      replace: true,
    })
  }

  return (
    <div className="flex min-h-0 min-w-0 flex-1 flex-col px-4 sm:px-6">
      <RuntimeLogsView
        projectId={projectId}
        resourceType="sites"
        resourceId={siteId}
        deploymentId={deploymentId}
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
