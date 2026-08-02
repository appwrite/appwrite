import { useState } from 'react'
import {
  Copy,
  Download,
  ExternalLink,
  FileCode,
  FileJson,
  LayoutList,
  Link2,
  Package,
  Play,
  RefreshCw,
  Square,
  Trash2,
  XCircle,
} from 'lucide-react'
import {
  openDialogAfterOverlayCloses,
  closeDialogBeforeOverlayUnmount,
} from '@/lib/utils/overlay-lock'
import { useNavigate } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { DeploymentDownloadType, type Models } from '@appwrite.io/console'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuSub,
  ContextMenuSubContent,
  ContextMenuSubTrigger,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { sdk } from '@/lib/appwrite/sdk'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import {
  deleteFunctionDeployment,
  deleteSiteDeployment,
  fetchFunctionDeployment,
  fetchSiteDeployment,
  Dependencies,
} from '@/lib/react-query/hooks'
import {
  isDeploymentCompleted,
  isDeploymentInProgress,
} from '@/lib/utils/deployment-status'
import { useT } from '@/lib/i18n/translate'

export type DeploymentListRowContextMenuDeployment = Pick<
  Models.Deployment,
  '$id' | 'status' | '$createdAt' | 'type'
>

interface DeploymentListRowContextMenuProps {
  variant: 'function' | 'site'
  projectId: string
  resourceId: string
  deployment: DeploymentListRowContextMenuDeployment
  isActive: boolean
  children: React.ReactNode
  onRequestCancelBuild: (deploymentId: string) => void
}

export function DeploymentListRowContextMenu({
  variant,
  projectId,
  resourceId,
  deployment,
  isActive,
  children,
  onRequestCancelBuild,
}: DeploymentListRowContextMenuProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [deletePending, setDeletePending] = useState(false)

  const deploymentPath =
    variant === 'function'
      ? `/projects/${projectId}/functions/${resourceId}/deployments/${deployment.$id}`
      : `/projects/${projectId}/sites/${resourceId}/deployments/${deployment.$id}`

  const deploymentHref = buildConsoleUrl(deploymentPath)

  const navigateToDeploymentOverview = () => {
    if (variant === 'function') {
      navigate({
        to: '/projects/$projectId/functions/$functionId/deployments/$deploymentId',
        params: {
          projectId,
          functionId: resourceId,
          deploymentId: deployment.$id,
        },
      })
    } else {
      navigate({
        to: '/projects/$projectId/sites/$siteId/deployments/$deploymentId',
        params: {
          projectId,
          siteId: resourceId,
          deploymentId: deployment.$id,
        },
      })
    }
  }

  const inProgress = isDeploymentInProgress(deployment.status)
  const canDownloadBuild = isDeploymentCompleted(deployment.status)
  const canActivate = !isActive && deployment.status === 'ready'
  const canDeleteFromMenu = !isActive && !inProgress

  const invalidateAfterFunctionMutation = async () => {
    await queryClient.refetchQueries({
      queryKey: ['deployments', 'project', projectId, resourceId],
    })
    await queryClient.refetchQueries({
      queryKey: ['function', 'project', projectId, resourceId],
    })
  }

  const invalidateAfterSiteMutation = () => {
    queryClient.invalidateQueries({
      queryKey: [...Dependencies.DEPLOYMENTS],
    })
    queryClient.invalidateQueries({
      queryKey: ['site', 'project', projectId, resourceId],
    })
  }

  const handleDownloadSource = () => {
    try {
      const projectSdk = sdk.forProject(projectId)
      const url =
        variant === 'function'
          ? projectSdk.functions.getDeploymentDownload({
              functionId: resourceId,
              deploymentId: deployment.$id,
              type: DeploymentDownloadType.Source,
            })
          : projectSdk.sites.getDeploymentDownload({
              siteId: resourceId,
              deploymentId: deployment.$id,
              type: DeploymentDownloadType.Source,
            })
      const urlWithMode = url + (url.includes('?') ? '&' : '?') + 'mode=admin'
      window.open(urlWithMode, '_blank')
      toast.success(t('Download started'))
    } catch {
      toast.error(t('Failed to download source code'))
    }
  }

  const handleDownloadBuild = () => {
    if (!canDownloadBuild) return
    try {
      const projectSdk = sdk.forProject(projectId)
      const url =
        variant === 'function'
          ? projectSdk.functions.getDeploymentDownload({
              functionId: resourceId,
              deploymentId: deployment.$id,
              type: DeploymentDownloadType.Output,
            })
          : projectSdk.sites.getDeploymentDownload({
              siteId: resourceId,
              deploymentId: deployment.$id,
              type: DeploymentDownloadType.Output,
            })
      const urlWithMode = url + (url.includes('?') ? '&' : '?') + 'mode=admin'
      window.open(urlWithMode, '_blank')
      toast.success(t('Download started'))
    } catch {
      toast.error(t('Failed to download build output'))
    }
  }

  const handleConfirmDelete = async () => {
    const deploymentId = deployment.$id
    closeDialogBeforeOverlayUnmount(() => {
      setDeleteDialogOpen(false)
    })
    setDeletePending(true)
    try {
      if (variant === 'function') {
        await deleteFunctionDeployment(projectId, resourceId, deploymentId)
        await invalidateAfterFunctionMutation()
      } else {
        await deleteSiteDeployment(projectId, resourceId, deploymentId)
        invalidateAfterSiteMutation()
      }
      toast.success(t('Deployment deleted successfully'))
    } catch (error) {
      toast.error(
        error instanceof Error
          ? error.message
          : t('Failed to delete deployment'),
      )
    } finally {
      setDeletePending(false)
    }
  }

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-56">
          <ContextMenuItem onSelect={navigateToDeploymentOverview}>
            <ContextMenuIcon icon={LayoutList} />
            {t('Overview')}
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Download} />
              {t('Download')}
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem onSelect={handleDownloadSource}>
                <ContextMenuIcon icon={FileCode} />
                {t('Source code')}
              </ContextMenuItem>
              <ContextMenuItem
                disabled={!canDownloadBuild}
                title={
                  !canDownloadBuild
                    ? t(
                        'Build output is available after the deployment has completed.',
                      )
                    : undefined
                }
                onSelect={handleDownloadBuild}
              >
                <ContextMenuIcon icon={Package} />
                {t('Build output')}
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              {t('Copy')}
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem
                onSelect={() => copyToClipboard('ID', deployment.$id)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy ID')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', deploymentHref)}
              >
                <ContextMenuIcon icon={Link2} />
                {t('Copy link')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  void copyResourceAsJson(() =>
                    variant === 'function'
                      ? fetchFunctionDeployment(
                          projectId,
                          resourceId,
                          deployment.$id,
                        )
                      : fetchSiteDeployment(
                          projectId,
                          resourceId,
                          deployment.$id,
                        ),
                  )
                }
              >
                <ContextMenuIcon icon={FileJson} />
                {t('Copy as JSON')}
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={() => openInNewTab(deploymentHref)}>
            <ContextMenuIcon icon={ExternalLink} />
            {t('Open in new tab')}
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => openInNewWindow(deploymentHref)}>
            <ContextMenuIcon icon={Square} />
            {t('Open in new window')}
          </ContextMenuItem>
          <ContextMenuSeparator />
          {canActivate && (
            <ContextMenuItem
              onSelect={async () => {
                try {
                  const projectSdk = sdk.forProject(projectId)
                  if (variant === 'function') {
                    await projectSdk.functions.updateFunctionDeployment({
                      functionId: resourceId,
                      deploymentId: deployment.$id,
                    })
                    queryClient.invalidateQueries({
                      queryKey: [
                        'deployments',
                        'project',
                        projectId,
                        resourceId,
                      ],
                    })
                    queryClient.invalidateQueries({
                      queryKey: ['function', 'project', projectId, resourceId],
                    })
                  } else {
                    await projectSdk.sites.updateSiteDeployment({
                      siteId: resourceId,
                      deploymentId: deployment.$id,
                    })
                    invalidateAfterSiteMutation()
                  }
                  toast.success(t('Deployment activated successfully'))
                } catch {
                  toast.error(t('Failed to activate deployment'))
                }
              }}
            >
              <ContextMenuIcon icon={Play} />
              {t('Activate')}
            </ContextMenuItem>
          )}
          <ContextMenuItem
            onSelect={async () => {
              try {
                const projectSdk = sdk.forProject(projectId)
                if (variant === 'function') {
                  await projectSdk.functions.createDuplicateDeployment({
                    functionId: resourceId,
                    deploymentId: deployment.$id,
                  })
                  queryClient.invalidateQueries({
                    queryKey: [
                      'deployments',
                      'project',
                      projectId,
                      resourceId,
                    ],
                  })
                } else {
                  await projectSdk.sites.createDuplicateDeployment({
                    siteId: resourceId,
                    deploymentId: deployment.$id,
                  })
                  invalidateAfterSiteMutation()
                }
                toast.success(t('Deployment rebuild started'))
              } catch {
                toast.error(t('Failed to redeploy'))
              }
            }}
          >
            <ContextMenuIcon icon={RefreshCw} />
            {t('Redeploy')}
          </ContextMenuItem>
          {inProgress && (
            <ContextMenuItem
              onSelect={() => {
                const id = deployment.$id
                openDialogAfterOverlayCloses(() => onRequestCancelBuild(id))
              }}
            >
              <ContextMenuIcon icon={XCircle} />
              {t('Cancel')}
            </ContextMenuItem>
          )}
          <ContextMenuSeparator />
          <ContextMenuItem
            disabled={!canDeleteFromMenu}
            title={
              !canDeleteFromMenu
                ? isActive
                  ? t('The active deployment cannot be deleted from the list')
                  : inProgress
                    ? t('Wait for the build to finish or cancel it first')
                    : undefined
                : undefined
            }
            onSelect={() => {
              if (!canDeleteFromMenu) return
              openDialogAfterOverlayCloses(() => setDeleteDialogOpen(true))
            }}
          >
            <ContextMenuIcon icon={Trash2} />
            {t('Delete')}
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete deployment')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Are you sure you want to delete this deployment? This action cannot be undone.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deletePending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={handleConfirmDelete}
              disabled={deletePending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
