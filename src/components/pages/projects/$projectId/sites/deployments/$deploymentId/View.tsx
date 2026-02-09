import { useParams } from '@tanstack/react-router'
import {
  useSiteDeployment,
  useProjectSite,
  useSiteDeployments,
  deleteSiteDeployment,
  Dependencies,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { DeploymentDownloadType } from '@appwrite.io/console'
import { DeploymentDetailView } from '@/components/global/shared/DeploymentDetailView'
import { toast } from 'sonner'

export function View() {
  const { projectId, siteId, deploymentId } = useParams({ strict: false })

  const { data: deployment, isLoading } = useSiteDeployment(
    projectId,
    siteId,
    deploymentId,
  )

  const { data: site } = useProjectSite(projectId, siteId)

  // Fetch all deployments for navigation
  const { deployments } = useSiteDeployments(
    projectId,
    siteId,
    0,
    1000, // Get enough to find current deployment
  )

  const handleDelete = async (deploymentId: string): Promise<void> => {
    if (!projectId || !siteId) {
      throw new Error('Project ID and Site ID are required')
    }
    await deleteSiteDeployment(projectId, siteId, deploymentId)
  }

  const handleDownloadSource = (
    projectId: string,
    resourceId: string,
    deploymentId: string,
  ) => {
    try {
      const projectSdk = sdk.forProject(projectId)
      const url = projectSdk.sites.getDeploymentDownload({
        siteId: resourceId,
        deploymentId,
        type: DeploymentDownloadType.Source,
      })
      const urlWithMode = url + (url.includes('?') ? '&' : '?') + 'mode=admin'
      window.open(urlWithMode, '_blank')
      toast.success('Download started')
    } catch (error) {
      toast.error('Failed to download source code')
    }
  }

  const handleDownloadBuild = (
    projectId: string,
    resourceId: string,
    deploymentId: string,
  ) => {
    try {
      const projectSdk = sdk.forProject(projectId)
      const url = projectSdk.sites.getDeploymentDownload({
        siteId: resourceId,
        deploymentId,
        type: DeploymentDownloadType.Output,
      })
      const urlWithMode = url + (url.includes('?') ? '&' : '?') + 'mode=admin'
      window.open(urlWithMode, '_blank')
      toast.success('Download started')
    } catch (error) {
      toast.error('Failed to download build output')
    }
  }

  const handleRedeploy = async (
    projectId: string,
    resourceId: string,
    deploymentId: string,
  ): Promise<void> => {
    if (!projectId || !resourceId || !deploymentId) {
      throw new Error('Project ID, Site ID, and Deployment ID are required')
    }

    const projectSdk = sdk.forProject(projectId)
    await projectSdk.sites.createDuplicateDeployment({
      siteId: resourceId,
      deploymentId,
    })
  }

  const handleActivate = async (
    projectId: string,
    resourceId: string,
    deploymentId: string,
  ): Promise<void> => {
    if (!projectId || !resourceId || !deploymentId) {
      throw new Error('Project ID, Site ID, and Deployment ID are required')
    }

    const projectSdk = sdk.forProject(projectId)
    await projectSdk.sites.updateSiteDeployment({
      siteId: resourceId,
      deploymentId,
    })
  }

  return (
    <DeploymentDetailView
      projectId={projectId!}
      resourceId={siteId!}
      deploymentId={deploymentId!}
      deployment={deployment}
      isLoading={isLoading}
      parentResource={{
        name: site?.name,
        deploymentId: site?.deploymentId,
      }}
      deployments={deployments || []}
      deploymentDetailRoute="/projects/$projectId/sites/$siteId/deployments/$deploymentId"
      listRoute="/projects/$projectId/sites/$siteId/"
      onDelete={handleDelete}
      onDownloadSource={handleDownloadSource}
      onDownloadBuild={handleDownloadBuild}
      onRedeploy={handleRedeploy}
      onActivate={handleActivate}
      invalidateQueries={[
        [...Dependencies.DEPLOYMENTS],
        [...Dependencies.SITE],
      ]}
      fallbackPath={`/projects/${projectId}/sites/${siteId}`}
    />
  )
}
