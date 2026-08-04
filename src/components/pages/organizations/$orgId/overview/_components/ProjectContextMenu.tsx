import { useState } from 'react'
import {
  Copy,
  Trash2,
  Link2,
  ExternalLink,
  Square,
  FileJson,
  LayoutDashboard,
  Users,
  Database,
  Folder,
  Zap,
  MessageSquare,
  Globe,
  Settings,
  Pin,
  PinOff,
} from 'lucide-react'
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
import { toast } from 'sonner'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { deleteProject, fetchProject } from '@/lib/react-query/hooks'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import {
  openDialogAfterOverlayCloses,
  closeDialogBeforeOverlayUnmount,
} from '@/lib/utils/overlay-lock'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { ConfirmNameDialog } from '@/components/global/shared/ConfirmNameDialog'
import { useT } from '@/lib/i18n/translate'

type ProjectContextMenuProject = {
  $id: string
  name?: string | null
  teamId: string
  region?: string | null
}

interface ProjectContextMenuProps {
  project: ProjectContextMenuProject
  showSettingsTab: boolean
  canDeleteProject: boolean
  onProjectDeleted?: (projectId: string) => Promise<void> | void
  canPinProjects?: boolean
  isPinned?: boolean
  canPinMore?: boolean
  onPinProject?: (projectId: string) => void
  isPinPending?: boolean
  children: React.ReactNode
}

export function ProjectContextMenu({
  project,
  showSettingsTab,
  canDeleteProject,
  onProjectDeleted,
  canPinProjects = false,
  isPinned = false,
  canPinMore = false,
  onPinProject,
  isPinPending = false,
  children,
}: ProjectContextMenuProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const projectHref = buildConsoleUrl(`/projects/${project.$id}`)
  const projectEndpoint = getApiEndpoint(project.region ?? undefined)
  const hasName = !!project.name

  const navigateToTab = (
    path:
      | '/projects/$projectId'
      | '/projects/$projectId/auth'
      | '/projects/$projectId/databases'
      | '/projects/$projectId/storage/$bucketId'
      | '/projects/$projectId/functions'
      | '/projects/$projectId/messaging'
      | '/projects/$projectId/sites'
      | '/projects/$projectId/settings',
  ) => {
    navigate({
      to: path,
      params: { projectId: project.$id },
    })
  }

  const deleteMutation = useMutation({
    mutationFn: async () => {
      await deleteProject(project.$id, project.region)
    },
    onSuccess: async () => {
      // Keep cards in sync across pinned and regular project queries.
      await queryClient.invalidateQueries({ queryKey: ['projects'] })
      await queryClient.invalidateQueries({
        queryKey: ['organization', project.teamId],
      })

      if (onProjectDeleted) {
        await onProjectDeleted(project.$id)
      }

      toast.success(`${project.name || t('Project')} ${t('has been deleted')}`)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error, t('Failed to delete project')))
    },
  })

  const handleDeleteClick = () => {
    openDialogAfterOverlayCloses(() => setDeleteDialogOpen(true))
  }

  const handleDelete = () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteDialogOpen(false))
    deleteMutation.mutate()
  }

  if (!project.$id) {
    return <>{children}</>
  }

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-56">
          <ContextMenuItem
            onSelect={() => {
              navigateToTab('/projects/$projectId')
            }}
          >
            <ContextMenuIcon icon={LayoutDashboard} />
            {t('Overview')}
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => {
              navigateToTab('/projects/$projectId/auth')
            }}
          >
            <ContextMenuIcon icon={Users} />
            {t('Auth')}
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => {
              navigateToTab('/projects/$projectId/databases')
            }}
          >
            <ContextMenuIcon icon={Database} />
            {t('Databases')}
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => {
              navigate({
                to: '/projects/$projectId/storage/$bucketId',
                params: { projectId: project.$id, bucketId: '-' },
              })
            }}
          >
            <ContextMenuIcon icon={Folder} />
            {t('Storage')}
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => {
              navigateToTab('/projects/$projectId/functions')
            }}
          >
            <ContextMenuIcon icon={Zap} />
            {t('Functions')}
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => {
              navigateToTab('/projects/$projectId/messaging')
            }}
          >
            <ContextMenuIcon icon={MessageSquare} />
            {t('Messaging')}
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => {
              navigateToTab('/projects/$projectId/sites')
            }}
          >
            <ContextMenuIcon icon={Globe} />
            {t('Sites')}
          </ContextMenuItem>
          {showSettingsTab && (
            <ContextMenuItem
              onSelect={() => {
                navigateToTab('/projects/$projectId/settings')
              }}
            >
              <ContextMenuIcon icon={Settings} />
              {t('Settings')}
            </ContextMenuItem>
          )}
          {canPinProjects && (isPinned || canPinMore) ? (
            <>
              <ContextMenuSeparator />
              <ContextMenuItem
                disabled={isPinPending}
                onSelect={() => onPinProject?.(project.$id)}
              >
                <ContextMenuIcon icon={isPinned ? PinOff : Pin} />
                {isPinned ? t('Unpin') : t('Pin')}
              </ContextMenuItem>
            </>
          ) : null}
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              {t('Copy')}
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem
                onSelect={() => copyToClipboard('ID', project.$id)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy ID')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() => copyToClipboard('Endpoint', projectEndpoint)}
              >
                <ContextMenuIcon icon={Link2} />
                {t('Copy endpoint')}
              </ContextMenuItem>
              {hasName && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', project.name)}
                >
                  <ContextMenuIcon icon={Copy} />
                  {t('Copy name')}
                </ContextMenuItem>
              )}
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', projectHref)}
              >
                <ContextMenuIcon icon={Link2} />
                {t('Copy link')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  void copyResourceAsJson(() => fetchProject(project.$id))
                }
              >
                <ContextMenuIcon icon={FileJson} />
                {t('Copy as JSON')}
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={() => openInNewTab(projectHref)}>
            <ContextMenuIcon icon={ExternalLink} />
            {t('Open in new tab')}
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => openInNewWindow(projectHref)}>
            <ContextMenuIcon icon={Square} />
            {t('Open in new window')}
          </ContextMenuItem>
          {canDeleteProject && (
            <>
              <ContextMenuSeparator />
              <ContextMenuItem onSelect={handleDeleteClick}>
                <ContextMenuIcon icon={Trash2} />
                {t('Delete')}
              </ContextMenuItem>
            </>
          )}
        </ContextMenuContent>
      </ContextMenu>

      <ConfirmNameDialog
        open={deleteDialogOpen}
        onOpenChange={setDeleteDialogOpen}
        title="Delete project"
        description={
          <>
            {t(
              'Are you sure you want to delete this project? This action cannot be undone.',
            )}
          </>
        }
        confirmValue={project.name?.trim() || project.$id}
        confirmPlaceholder="Enter project name"
        onConfirm={handleDelete}
        isConfirming={deleteMutation.isPending}
      />
    </>
  )
}
