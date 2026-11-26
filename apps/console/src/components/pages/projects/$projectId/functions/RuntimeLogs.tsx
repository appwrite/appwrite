import { useNavigate } from '@tanstack/react-router'
import {
  useFunctionDeployments,
  useProjectFunction,
} from '@/lib/react-query/hooks'
import { RuntimeLogsView } from '@/components/global/shared/RuntimeLogsView'
import { Route } from '@/routes/_public/projects.$projectId.functions.$functionId.runtime-logs'
import { DeploymentSelect } from '../shared/DeploymentSelect'

/** Newest deployments offered in the picker. */
const DEPLOYMENTS_LIMIT = 100

export function View() {
  const { projectId, functionId } = Route.useParams()
  const search = Route.useSearch()
  const navigate = useNavigate()

  const { data: func } = useProjectFunction(projectId, functionId)
  const { deployments, isLoading } = useFunctionDeployments(
    projectId,
    functionId,
    0,
    DEPLOYMENTS_LIMIT,
  )

  const deploymentId =
    search.deploymentId || func?.deploymentId || deployments[0]?.$id

  const handleDeploymentChange = (value: string) => {
    navigate({
      to: '.',
      search: { deploymentId: value },
      replace: true,
    })
  }

  return (
    <div className="flex min-h-0 min-w-0 flex-1 flex-col">
      <RuntimeLogsView
        projectId={projectId}
        resourceType="functions"
        resourceId={functionId}
        deploymentId={deploymentId}
        toolbarStart={
          <DeploymentSelect
            deployments={deployments}
            activeDeploymentId={func?.deploymentId}
            value={deploymentId}
            onChange={handleDeploymentChange}
            disabled={isLoading && !deploymentId}
          />
        }
      />
    </div>
  )
}
