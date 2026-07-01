import { useState } from 'react'
import {
  Copy,
  Users,
  Trash2,
  ExternalLink,
  Square,
  Link2,
  FileJson,
  LayoutList,
} from 'lucide-react'
import { toast } from 'sonner'
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
import { useNavigate } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { deleteProjectTeam, fetchTeam } from '@/lib/react-query/hooks/users'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'

export type TeamContextMenuTeam = {
  id: string
  name?: string | null
}

interface TeamContextMenuProps {
  projectId: string
  team: TeamContextMenuTeam
  children: React.ReactNode
}

export function TeamContextMenu({
  projectId,
  team,
  children,
}: TeamContextMenuProps) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const deleteMutation = useMutation({
    mutationFn: () => deleteProjectTeam(projectId, team.id),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['teams', 'project', projectId],
      })
      toast.success('Team deleted')
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete team')
    },
  })

  const teamHref = buildConsoleUrl(
    `/projects/${projectId}/auth/teams/${team.id}`,
  )

  const navigateToTab = (tab: string) => {
    const base = `/projects/${projectId}/auth/teams/${team.id}`
    const path = tab === 'overview' ? base : `${base}/${tab}`
    navigate({
      to: path as '/projects/$projectId/auth/teams/$teamId',
      params: { projectId, teamId: team.id },
    })
  }

  const handleDeleteClick = () => {
    setDeleteDialogOpen(true)
  }

  const hasName = !!team.name

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-56">
          <ContextMenuItem onSelect={() => navigateToTab('overview')}>
            <ContextMenuIcon icon={LayoutList} />
            Overview
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => navigateToTab('members')}>
            <ContextMenuIcon icon={Users} />
            Memberships
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              Copy
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem onSelect={() => copyToClipboard('ID', team.id)}>
                <ContextMenuIcon icon={Copy} />
                Copy ID
              </ContextMenuItem>
              {hasName && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', team.name)}
                >
                  <ContextMenuIcon icon={Users} />
                  Copy name
                </ContextMenuItem>
              )}
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', teamHref)}
              >
                <ContextMenuIcon icon={Link2} />
                Copy link
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  void copyResourceAsJson(() => fetchTeam(projectId, team.id))
                }
              >
                <ContextMenuIcon icon={FileJson} />
                Copy as JSON
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={() => openInNewTab(teamHref)}>
            <ContextMenuIcon icon={ExternalLink} />
            Open in new tab
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => openInNewWindow(teamHref)}>
            <ContextMenuIcon icon={Square} />
            Open in new window
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={handleDeleteClick}>
            <ContextMenuIcon icon={Trash2} />
            Delete
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>Delete team</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete this team? This action cannot be
              undone.
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
