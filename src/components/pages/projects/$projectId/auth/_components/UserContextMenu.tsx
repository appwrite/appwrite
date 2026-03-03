import { useState } from 'react'
import {
  Copy,
  User2,
  Trash2,
  ExternalLink,
  Square,
  Link2,
  FileJson,
  LayoutList,
  Users,
  Shield,
  Target,
  Activity,
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
import { deleteProjectUser } from '@/lib/react-query/hooks/users'
import {
  buildConsoleUrl,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
  toPrettyJson,
} from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'

export type UserContextMenuUser = {
  $id: string
  name?: string | null
  email?: string | null
  phone?: string | null
  emailVerification?: boolean | null
  phoneVerification?: boolean | null
  status?: boolean | null
}

interface UserContextMenuProps {
  projectId: string
  user: UserContextMenuUser
  children: React.ReactNode
}

export function UserContextMenu({
  projectId,
  user,
  children,
}: UserContextMenuProps) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const hasName = !!user.name

  const deleteMutation = useMutation({
    mutationFn: () => deleteProjectUser(projectId, user.$id),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['users', 'project', projectId],
      })
      toast.success('User deleted')
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete user')
    },
  })

  const userHref = buildConsoleUrl(
    `/projects/${projectId}/auth/users/${user.$id}`,
  )

  const navigateToTab = (tab: string) => {
    const base = `/projects/${projectId}/auth/users/${user.$id}`
    const path = tab === 'overview' ? base : `${base}/${tab}`
    navigate({
      to: path as '/projects/$projectId/auth/users/$userId',
      params: { projectId, userId: user.$id },
    })
  }

  const handleDeleteClick = () => {
    setDeleteDialogOpen(true)
  }

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-56">
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={LayoutList} />
              Tabs
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem onSelect={() => navigateToTab('overview')}>
                <ContextMenuIcon icon={LayoutList} />
                Overview
              </ContextMenuItem>
              <ContextMenuItem onSelect={() => navigateToTab('memberships')}>
                <ContextMenuIcon icon={Users} />
                Memberships
              </ContextMenuItem>
              <ContextMenuItem onSelect={() => navigateToTab('identities')}>
                <ContextMenuIcon icon={Shield} />
                Identities
              </ContextMenuItem>
              <ContextMenuItem onSelect={() => navigateToTab('targets')}>
                <ContextMenuIcon icon={Target} />
                Targets
              </ContextMenuItem>
              <ContextMenuItem onSelect={() => navigateToTab('sessions')}>
                <ContextMenuIcon icon={Activity} />
                Sessions
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              Copy
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem onSelect={() => copyToClipboard('ID', user.$id)}>
                <ContextMenuIcon icon={Copy} />
                Copy ID
              </ContextMenuItem>
              {hasName && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', user.name)}
                >
                  <ContextMenuIcon icon={User2} />
                  Copy name
                </ContextMenuItem>
              )}
              <ContextMenuItem onSelect={() => copyToClipboard('Link', userHref)}>
                <ContextMenuIcon icon={Link2} />
                Copy link
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  copyToClipboard(
                    'JSON',
                    toPrettyJson(
                      {
                        id: user.$id,
                        name: user.name ?? null,
                        email: user.email ?? null,
                        phone: user.phone ?? null,
                        status: user.status ?? null,
                        emailVerification: user.emailVerification ?? null,
                        phoneVerification: user.phoneVerification ?? null,
                      },
                    ),
                  )
                }
              >
                <ContextMenuIcon icon={FileJson} />
                Copy as JSON
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem
            onSelect={() => openInNewTab(userHref)}
          >
            <ContextMenuIcon icon={ExternalLink} />
            Open in new tab
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => openInNewWindow(userHref)}
          >
            <ContextMenuIcon icon={Square} />
            Open in new window
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem
            onSelect={handleDeleteClick}
          >
            <ContextMenuIcon icon={Trash2} />
            Delete
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>Delete user</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete this user? This action cannot be
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
