import { useState } from 'react'
import {
  Copy,
  Users,
  Pencil,
  Plus,
  Activity,
  Trash2,
  UserPlus,
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
import { deleteProjectTeam } from '@/lib/react-query/hooks/users'

export type TeamContextMenuTeam = {
  id: string
  name?: string | null
}

interface TeamContextMenuProps {
  projectId: string
  team: TeamContextMenuTeam
  children: React.ReactNode
}

async function copyToClipboard(label: string, value?: string | null) {
  if (!value) return
  try {
    await navigator.clipboard.writeText(value)
    toast.success(`${label} copied to clipboard`)
  } catch {
    toast.error('Failed to copy')
  }
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

  const handleUpdateTeam = () => {
    navigate({
      to: '/projects/$projectId/auth/teams/$teamId',
      params: { projectId, teamId: team.id },
      hash: 'team-details',
    })
  }

  const handleAddPreferences = () => {
    navigate({
      to: '/projects/$projectId/auth/teams/$teamId',
      params: { projectId, teamId: team.id },
      hash: 'team-preferences',
    })
  }

  const handleAddMembers = () => {
    navigate({
      to: '/projects/$projectId/auth/teams/$teamId/members',
      params: { projectId, teamId: team.id },
    })
  }

  const handleViewActivity = () => {
    navigate({
      to: '/projects/$projectId/auth/teams/$teamId/members',
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
          <ContextMenuItem onSelect={handleUpdateTeam}>
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <Pencil className="size-4" />
            </span>
            Update team
          </ContextMenuItem>
          <ContextMenuItem onSelect={handleAddPreferences}>
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <Plus className="size-4" />
            </span>
            Add preferences
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={handleAddMembers}>
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <UserPlus className="size-4" />
            </span>
            Add members
          </ContextMenuItem>
          <ContextMenuItem onSelect={handleViewActivity}>
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <Activity className="size-4" />
            </span>
            View activity
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                <Copy className="size-4" />
              </span>
              Copy
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem onSelect={() => copyToClipboard('ID', team.id)}>
                <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                  <Copy className="size-4" />
                </span>
                Copy ID
              </ContextMenuItem>
              {hasName && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', team.name)}
                >
                  <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                    <Users className="size-4" />
                  </span>
                  Copy name
                </ContextMenuItem>
              )}
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem
            onSelect={handleDeleteClick}
            className="text-destructive focus:text-destructive"
          >
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <Trash2 className="size-4" />
            </span>
            Delete
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
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
