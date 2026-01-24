import { useParams } from '@tanstack/react-router'
import {
  useFunctionDeployment,
  useProjectFunction,
  useFunctionDeployments,
  deleteFunctionDeployment,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { DeploymentDetailView } from '@/components/global/shared/DeploymentDetailView'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { toast } from 'sonner'

export function FunctionDeploymentDetailView() {
  const { projectId, functionId, deploymentId } = useParams({ strict: false })

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
      deploymentDetailRoute="/projects/$projectId/functions/$functionId/deployments/$deploymentId"
      listRoute="/projects/$projectId/functions/$functionId"
      onDelete={handleDelete}
      onDownload={handleDownload}
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
