import { useParams, useNavigate } from '@tanstack/react-router'
import { Query } from '@appwrite.io/console'
import {
  useSiteDeployment,
  useProjectSite,
  useSiteDeployments,
  useSiteLogs,
  deleteSiteDeployment,
  Dependencies,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { DeploymentDetailView } from '@/components/global/shared/DeploymentDetailView'
import { toast } from 'sonner'


export function SiteDeploymentDetailView() {
  const { projectId, siteId, deploymentId } = useParams({ strict: false })
  const navigate = useNavigate()

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

  // Get logs count for this deployment
  const { total: logsTotal } = useSiteLogs(
    projectId,
    siteId,
    0,
    1,
    deploymentId ? [Query.equal('deploymentId', deploymentId)] : undefined,
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

  const handleNavigateToLogs = (deploymentId: string) => {
    navigate({
      to: '/projects/$projectId/sites/$siteId/logs',
      params: {
        projectId: projectId!,
        siteId: siteId!,
      },
      search: { deploymentId } as any,
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
      relatedData={{ total: logsTotal }}
      deploymentDetailRoute="/projects/$projectId/sites/$siteId/deployments/$deploymentId"
      listRoute="/projects/$projectId/sites/$siteId/"
      relatedRoute="/projects/$projectId/sites/$siteId/logs"
      onDelete={handleDelete}
      onDownload={handleDownload}
      onNavigateToRelated={handleNavigateToLogs}
      relatedDataLabel="Logs"
      invalidateQueries={[[...Dependencies.DEPLOYMENTS], [...Dependencies.SITE]]}
      fallbackPath={`/projects/${projectId}/sites/${siteId}`}
    />
  )
}
