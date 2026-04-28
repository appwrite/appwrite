import { useState } from 'react'
import {
  Copy,
  ExternalLink,
  FileJson,
  FolderGit,
  Globe,
  LayoutList,
  Link2,
  Play,
  RefreshCw,
  ScrollText,
  Settings,
  Shield,
  Square,
  Trash2,
  Variable,
  XCircle,
} from 'lucide-react'
import { useNavigate } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
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
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
  toPrettyJson,
} from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import {
  useProject,
  useOrganizationScopes,
  deleteFunctionDeployment,
  deleteSiteDeployment,
  Dependencies,
} from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  canShowFunctionSecuritySettings,
  canShowSiteSettingsTab,
} from '@/lib/console-access-checks'
import { isDeploymentInProgress } from '@/lib/utils/deployment-status'

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
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [deletePending, setDeletePending] = useState(false)

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const showFunctionSecuritySettings = canShowFunctionSecuritySettings(
    access,
    features,
  )
  const showSiteSettingsTab = canShowSiteSettingsTab(access, features)

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

  const navigateParentTab = (tab: string) => {
    if (variant === 'function') {
      const params = { projectId, functionId: resourceId }
      switch (tab) {
        case 'deployments':
          navigate({
            to: '/projects/$projectId/functions/$functionId',
            params,
          })
          break
        case 'domains':
          navigate({
            to: '/projects/$projectId/functions/$functionId/domains',
            params,
          })
          break
        case 'executions':
          navigate({
            to: '/projects/$projectId/functions/$functionId/executions',
            params,
          })
          break
        case 'variables':
          navigate({
            to: '/projects/$projectId/functions/$functionId/variables',
            params,
          })
          break
        case 'security':
          navigate({
            to: '/projects/$projectId/functions/$functionId/security',
            params,
          })
          break
        case 'settings':
          navigate({
            to: '/projects/$projectId/functions/$functionId/settings',
            params,
          })
          break
        default:
          break
      }
    } else {
      const params = { projectId, siteId: resourceId }
      switch (tab) {
        case 'deployments':
          navigate({
            to: '/projects/$projectId/sites/$siteId',
            params,
          })
          break
        case 'domains':
          navigate({
            to: '/projects/$projectId/sites/$siteId/domains',
            params,
          })
          break
        case 'logs':
          navigate({
            to: '/projects/$projectId/sites/$siteId/logs',
            params,
          })
          break
        case 'variables':
          navigate({
            to: '/projects/$projectId/sites/$siteId/variables',
            params,
          })
          break
        case 'settings':
          navigate({
            to: '/projects/$projectId/sites/$siteId/settings',
            params,
          })
          break
        default:
          break
      }
    }
  }

  const jsonPayload = {
    $id: deployment.$id,
    status: deployment.status ?? null,
    type: deployment.type ?? null,
    $createdAt: deployment.$createdAt,
  }

  const inProgress = isDeploymentInProgress(deployment.status)
  const canActivate = !isActive && deployment.status === 'ready'
  const showRedeploy =
    variant === 'site' ? true : !isActive
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

  const handleConfirmDelete = async () => {
    setDeletePending(true)
    try {
      if (variant === 'function') {
        await deleteFunctionDeployment(projectId, resourceId, deployment.$id)
        await invalidateAfterFunctionMutation()
      } else {
        await deleteSiteDeployment(projectId, resourceId, deployment.$id)
        invalidateAfterSiteMutation()
      }
      toast.success('Deployment deleted successfully')
      setDeleteDialogOpen(false)
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : 'Failed to delete deployment',
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
            Overview
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => navigateParentTab('deployments')}>
            <ContextMenuIcon icon={FolderGit} />
            Deployments
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => navigateParentTab('domains')}>
            <ContextMenuIcon icon={Globe} />
            Domains
          </ContextMenuItem>
          {variant === 'function' ? (
            <ContextMenuItem onSelect={() => navigateParentTab('executions')}>
              <ContextMenuIcon icon={Play} />
              Executions
            </ContextMenuItem>
          ) : (
            <ContextMenuItem onSelect={() => navigateParentTab('logs')}>
              <ContextMenuIcon icon={ScrollText} />
              Logs
            </ContextMenuItem>
          )}
          {variant === 'function' && showFunctionSecuritySettings && (
            <>
              <ContextMenuItem
                onSelect={() => navigateParentTab('variables')}
              >
                <ContextMenuIcon icon={Variable} />
                Variables
              </ContextMenuItem>
              <ContextMenuItem onSelect={() => navigateParentTab('security')}>
                <ContextMenuIcon icon={Shield} />
                Security
              </ContextMenuItem>
              <ContextMenuItem onSelect={() => navigateParentTab('settings')}>
                <ContextMenuIcon icon={Settings} />
                Settings
              </ContextMenuItem>
            </>
          )}
          {variant === 'site' && showSiteSettingsTab && (
            <>
              <ContextMenuItem
                onSelect={() => navigateParentTab('variables')}
              >
                <ContextMenuIcon icon={Variable} />
                Variables
              </ContextMenuItem>
              <ContextMenuItem onSelect={() => navigateParentTab('settings')}>
                <ContextMenuIcon icon={Settings} />
                Settings
              </ContextMenuItem>
            </>
          )}
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              Copy
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem
                onSelect={() => copyToClipboard('ID', deployment.$id)}
              >
                <ContextMenuIcon icon={Copy} />
                Copy ID
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', deploymentHref)}
              >
                <ContextMenuIcon icon={Link2} />
                Copy link
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  copyToClipboard('JSON', toPrettyJson(jsonPayload))
                }
              >
                <ContextMenuIcon icon={FileJson} />
                Copy as JSON
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={() => openInNewTab(deploymentHref)}>
            <ContextMenuIcon icon={ExternalLink} />
            Open in new tab
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => openInNewWindow(deploymentHref)}>
            <ContextMenuIcon icon={Square} />
            Open in new window
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
                  toast.success('Deployment activated successfully')
                } catch {
                  toast.error('Failed to activate deployment')
                }
              }}
            >
              <ContextMenuIcon icon={Play} />
              Activate
            </ContextMenuItem>
          )}
          {showRedeploy && (
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
                  toast.success('Deployment rebuild started')
                } catch {
                  toast.error('Failed to redeploy')
                }
              }}
            >
              <ContextMenuIcon icon={RefreshCw} />
              Redeploy
            </ContextMenuItem>
          )}
          {inProgress && (
            <ContextMenuItem
              onSelect={() => onRequestCancelBuild(deployment.$id)}
            >
              <ContextMenuIcon icon={XCircle} />
              Cancel
            </ContextMenuItem>
          )}
          <ContextMenuSeparator />
          <ContextMenuItem
            disabled={!canDeleteFromMenu}
            title={
              !canDeleteFromMenu
                ? isActive
                  ? 'The active deployment cannot be deleted from the list'
                  : inProgress
                    ? 'Wait for the build to finish or cancel it first'
                    : undefined
                : undefined
            }
            onSelect={() => {
              if (!canDeleteFromMenu) return
              setDeleteDialogOpen(true)
            }}
          >
            <ContextMenuIcon icon={Trash2} />
            Delete
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>Delete deployment</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete this deployment? This action
              cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deletePending}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={handleConfirmDelete}
              disabled={deletePending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
