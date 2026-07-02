import { useParams } from '@tanstack/react-router'
import {
  useFunctionDeployment,
  useProjectFunction,
  deleteFunctionDeployment,
  cancelFunctionDeployment,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { DeploymentDownloadType } from '@appwrite.io/console'
import { DeploymentDetailView } from '@/components/global/shared/DeploymentDetailView'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'

export function View() {
  const t = useT()
  const { projectId, functionId, deploymentId } = useParams({ strict: false })

  const { data: deployment, isLoading } = useFunctionDeployment(
    projectId,
    functionId,
    deploymentId,
  )

  const { data: func } = useProjectFunction(projectId, functionId)

  const handleDelete = async (deploymentId: string): Promise<void> => {
    if (!projectId || !functionId) {
      throw new Error('Project ID and Function ID are required')
    }
    await deleteFunctionDeployment(projectId, functionId, deploymentId)
  }

  const handleCancelBuild = async (deploymentId: string): Promise<void> => {
    if (!projectId || !functionId) {
      throw new Error('Project ID and Function ID are required')
    }
    await cancelFunctionDeployment(projectId, functionId, deploymentId)
  }

  const handleDownloadSource = (
    projectId: string,
    resourceId: string,
    deploymentId: string,
  ) => {
    try {
      const projectSdk = sdk.forProject(projectId)
      const url = projectSdk.functions.getDeploymentDownload({
        functionId: resourceId,
        deploymentId,
        type: DeploymentDownloadType.Source,
      })
      const urlWithMode = url + (url.includes('?') ? '&' : '?') + 'mode=admin'
      window.open(urlWithMode, '_blank')
      toast.success(t('Download started'))
    } catch {
      toast.error(t('Failed to download source code'))
    }
  }

  const handleDownloadBuild = (
    projectId: string,
    resourceId: string,
    deploymentId: string,
  ) => {
    try {
      const projectSdk = sdk.forProject(projectId)
      const url = projectSdk.functions.getDeploymentDownload({
        functionId: resourceId,
        deploymentId,
        type: DeploymentDownloadType.Output,
      })
      const urlWithMode = url + (url.includes('?') ? '&' : '?') + 'mode=admin'
      window.open(urlWithMode, '_blank')
      toast.success(t('Download started'))
    } catch {
      toast.error(t('Failed to download build output'))
    }
  }

  const handleRedeploy = async (
    projectId: string,
    resourceId: string,
    deploymentId: string,
  ): Promise<void> => {
    if (!projectId || !resourceId || !deploymentId) {
      throw new Error('Project ID, Function ID, and Deployment ID are required')
    }

    const projectSdk = sdk.forProject(projectId)
    await projectSdk.functions.createDuplicateDeployment({
      functionId: resourceId,
      deploymentId,
    })
  }

  const handleActivate = async (
    projectId: string,
    resourceId: string,
    deploymentId: string,
  ): Promise<void> => {
    if (!projectId || !resourceId || !deploymentId) {
      throw new Error('Project ID, Function ID, and Deployment ID are required')
    }

    const projectSdk = sdk.forProject(projectId)
    await projectSdk.functions.updateFunctionDeployment({
      functionId: resourceId,
      deploymentId,
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
      deployments={[]}
      deploymentDetailRoute="/projects/$projectId/functions/$functionId/deployments/$deploymentId"
      listRoute="/projects/$projectId/functions/$functionId"
      onDelete={handleDelete}
      onCancelBuild={handleCancelBuild}
      onDownloadSource={handleDownloadSource}
      onDownloadBuild={handleDownloadBuild}
      onRedeploy={handleRedeploy}
      onActivate={handleActivate}
      showRuntime={true}
      RuntimeIcon={RuntimeIcon as unknown}
      invalidateQueries={[
        ['deployments', 'project', projectId!, functionId!],
        ['function', 'project', projectId!, functionId!],
        ['deployment', 'function', projectId!, functionId!, deploymentId!],
      ]}
      fallbackPath={`/projects/${projectId}/functions/${functionId}`}
    />
  )
}
