import { useParams } from '@tanstack/react-router'
import {
  useSiteDeployment,
  useProjectSite,
  deleteSiteDeployment,
  cancelSiteDeployment,
  Dependencies,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { DeploymentDownloadType } from '@appwrite.io/console'
import { DeploymentDetailView } from '@/components/global/shared/DeploymentDetailView'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'

export function View() {
  const t = useT()
  const { projectId, siteId, deploymentId } = useParams({ strict: false })

  const { data: deployment, isLoading } = useSiteDeployment(
    projectId,
    siteId,
    deploymentId,
  )

  const { data: site } = useProjectSite(projectId, siteId)

  const handleDelete = async (deploymentId: string): Promise<void> => {
    if (!projectId || !siteId) {
      throw new Error('Project ID and Site ID are required')
    }
    await deleteSiteDeployment(projectId, siteId, deploymentId)
  }

  const handleCancelBuild = async (deploymentId: string): Promise<void> => {
    if (!projectId || !siteId) {
      throw new Error('Project ID and Site ID are required')
    }
    await cancelSiteDeployment(projectId, siteId, deploymentId)
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
      const url = projectSdk.sites.getDeploymentDownload({
        siteId: resourceId,
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
        framework: site?.framework,
      }}
      deployments={[]}
      deploymentDetailRoute="/projects/$projectId/sites/$siteId/deployments/$deploymentId"
      listRoute="/projects/$projectId/sites/$siteId/"
      onDelete={handleDelete}
      onCancelBuild={handleCancelBuild}
      onDownloadSource={handleDownloadSource}
      onDownloadBuild={handleDownloadBuild}
      onRedeploy={handleRedeploy}
      onActivate={handleActivate}
      invalidateQueries={[
        [...Dependencies.DEPLOYMENTS],
        [...Dependencies.SITE],
        ['deployment', 'site', projectId!, siteId!, deploymentId!],
      ]}
      fallbackPath={`/projects/${projectId}/sites/${siteId}`}
    />
  )
}
