import { useParams, useNavigate } from '@tanstack/react-router'
import { Query } from '@appwrite.io/console'
import {
  useFunctionDeployment,
  useProjectFunction,
  useFunctionDeployments,
  useFunctionExecutions,
  deleteFunctionDeployment,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { DeploymentDetailView } from '@/components/global/shared/DeploymentDetailView'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { toast } from 'sonner'

export function FunctionDeploymentDetailView() {
  const { projectId, functionId, deploymentId } = useParams({ strict: false })
  const navigate = useNavigate()

  const { data: deployment, isLoading } = useFunctionDeployment(
    projectId,
    functionId,
    deploymentId,
  )

  const { data: func } = useProjectFunction(projectId, functionId)

  // Fetch all deployments for navigation
  const { deployments } = useFunctionDeployments(
    projectId,
    functionId,
    0,
    1000, // Get enough to find current deployment
  )

  // Get executions count for this deployment
  const { total: executionsTotal } = useFunctionExecutions(
    projectId,
    functionId,
    0,
    1,
    deploymentId ? [Query.equal('deploymentId', deploymentId)] : undefined,
  )

  const handleDelete = async (deploymentId: string): Promise<void> => {
    if (!projectId || !functionId) {
      throw new Error('Project ID and Function ID are required')
    }
    await deleteFunctionDeployment(projectId, functionId, deploymentId)
  }

  const handleDownload = (
    projectId: string,
    resourceId: string,
    deploymentId: string,
  ) => {
    try {
      const projectSdk = sdk.forProject(projectId)
      const url = projectSdk.functions.getDeploymentDownload({
        functionId: resourceId,
        deploymentId,
      })
      const urlWithMode = url + (url.includes('?') ? '&' : '?') + 'mode=admin'
      window.open(urlWithMode, '_blank')
      toast.success('Download started')
    } catch (error) {
      toast.error('Failed to download deployment')
    }
  }

  const handleNavigateToExecutions = (deploymentId: string) => {
    navigate({
      to: '/projects/$projectId/functions/$functionId/executions',
      params: {
        projectId: projectId!,
        functionId: functionId!,
      },
      search: { deploymentId } as any,
    })
  }

  return (
    <DeploymentDetailView
      projectId={projectId!}
      resourceId={functionId!}
      deploymentId={deploymentId!}
      deployment={deployment}
      isLoading={isLoading}
      parentResource={{
        name: func?.name,
        deploymentId: func?.deploymentId,
        runtime: func?.runtime,
      }}
      deployments={deployments || []}
      relatedData={{ total: executionsTotal }}
      deploymentDetailRoute="/projects/$projectId/functions/$functionId/deployments/$deploymentId"
      listRoute="/projects/$projectId/functions/$functionId"
      relatedRoute="/projects/$projectId/functions/$functionId/executions"
      onDelete={handleDelete}
      onDownload={handleDownload}
      onNavigateToRelated={handleNavigateToExecutions}
      relatedDataLabel="Executions"
      showRuntime={true}
      RuntimeIcon={RuntimeIcon as any}
      invalidateQueries={[
        ['deployments', 'project', projectId!, functionId!],
        ['function', 'project', projectId!, functionId!],
      ]}
      fallbackPath={`/projects/${projectId}/functions/${functionId}`}
    />
  )
}
