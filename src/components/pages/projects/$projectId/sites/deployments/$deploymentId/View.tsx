import { useParams } from '@tanstack/react-router'
import {
  useSiteDeployment,
  useProjectSite,
  useSiteDeployments,
  deleteSiteDeployment,
  Dependencies,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { DeploymentDetailView } from '@/components/global/shared/DeploymentDetailView'
import { toast } from 'sonner'


export function SiteDeploymentDetailView() {
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

  const handleDownload = (
    projectId: string,
    resourceId: string,
    deploymentId: string,
  ) => {
    try {
      const projectSdk = sdk.forProject(projectId)
      const url = projectSdk.sites.getDeploymentDownload({
        siteId: resourceId,
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
      onDownload={handleDownload}
      invalidateQueries={[[...Dependencies.DEPLOYMENTS], [...Dependencies.SITE]]}
      fallbackPath={`/projects/${projectId}/sites/${siteId}`}
    />
  )
}
