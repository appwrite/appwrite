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
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
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
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'

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

      toast.success(`${project.name || 'Project'} has been deleted`)
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error, 'Failed to delete project'))
    },
  })

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
            Overview
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => {
              navigateToTab('/projects/$projectId/auth')
            }}
          >
            <ContextMenuIcon icon={Users} />
            Auth
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => {
              navigateToTab('/projects/$projectId/databases')
            }}
          >
            <ContextMenuIcon icon={Database} />
            Databases
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
            Storage
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => {
              navigateToTab('/projects/$projectId/functions')
            }}
          >
            <ContextMenuIcon icon={Zap} />
            Functions
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => {
              navigateToTab('/projects/$projectId/messaging')
            }}
          >
            <ContextMenuIcon icon={MessageSquare} />
            Messaging
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => {
              navigateToTab('/projects/$projectId/sites')
            }}
          >
            <ContextMenuIcon icon={Globe} />
            Sites
          </ContextMenuItem>
          {showSettingsTab && (
            <ContextMenuItem
              onSelect={() => {
                navigateToTab('/projects/$projectId/settings')
              }}
            >
              <ContextMenuIcon icon={Settings} />
              Settings
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
                {isPinned ? 'Unpin' : 'Pin'}
              </ContextMenuItem>
            </>
          ) : null}
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              Copy
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem
                onSelect={() => copyToClipboard('ID', project.$id)}
              >
                <ContextMenuIcon icon={Copy} />
                Copy ID
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() => copyToClipboard('Endpoint', projectEndpoint)}
              >
                <ContextMenuIcon icon={Link2} />
                Copy endpoint
              </ContextMenuItem>
              {hasName && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', project.name)}
                >
                  <ContextMenuIcon icon={Copy} />
                  Copy name
                </ContextMenuItem>
              )}
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', projectHref)}
              >
                <ContextMenuIcon icon={Link2} />
                Copy link
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  void copyResourceAsJson(() => fetchProject(project.$id))
                }
              >
                <ContextMenuIcon icon={FileJson} />
                Copy as JSON
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={() => openInNewTab(projectHref)}>
            <ContextMenuIcon icon={ExternalLink} />
            Open in new tab
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => openInNewWindow(projectHref)}>
            <ContextMenuIcon icon={Square} />
            Open in new window
          </ContextMenuItem>
          {canDeleteProject && (
            <>
              <ContextMenuSeparator />
              <ContextMenuItem onSelect={() => setDeleteDialogOpen(true)}>
                <ContextMenuIcon icon={Trash2} />
                Delete
              </ContextMenuItem>
            </>
          )}
        </ContextMenuContent>
      </ContextMenu>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>Delete project</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete this project? This action cannot
              be undone.
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deleteMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={() => deleteMutation.mutate()}
              disabled={deleteMutation.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
