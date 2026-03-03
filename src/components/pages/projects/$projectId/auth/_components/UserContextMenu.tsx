import { useState } from 'react'
import {
  Copy,
  Mail,
  Phone,
  User2,
  Pencil,
  Plus,
  BadgeCheck,
  ShieldOff,
  Trash2,
  ExternalLink,
  Square,
  Link2,
  FileJson,
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
import {
  deleteProjectUser,
  useUpdateUserEmailVerification,
  useUpdateUserPhoneVerification,
  useUpdateUserStatus,
} from '@/lib/react-query/hooks/users'
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
  const updateEmailVerification = useUpdateUserEmailVerification(
    projectId,
    user.$id,
  )
  const updatePhoneVerification = useUpdateUserPhoneVerification(
    projectId,
    user.$id,
  )
  const updateStatus = useUpdateUserStatus(projectId, user.$id)

  const hasEmail = !!user.email
  const hasPhone = !!user.phone
  const hasName = !!user.name
  const emailVerified = !!user.emailVerification
  const phoneVerified = !!user.phoneVerification
  const isBlocked = user.status === false

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

  const handleUpdateUser = () => {
    navigate({
      to: '/projects/$projectId/auth/users/$userId',
      params: { projectId, userId: user.$id },
      hash: 'user-details',
    })
  }

  const handleAddPreferences = () => {
    navigate({
      to: '/projects/$projectId/auth/users/$userId',
      params: { projectId, userId: user.$id },
      hash: 'user-preferences',
    })
  }

  const userHref = buildConsoleUrl(
    `/projects/${projectId}/auth/users/${user.$id}`,
  )

  const handleVerifyEmail = () => {
    if (!hasEmail || updateEmailVerification.isPending) return
    updateEmailVerification.mutate(!emailVerified, {
      onSuccess: () => {
        toast.success(
          `Email has been ${emailVerified ? 'unverified' : 'verified'}`,
        )
      },
      onError: (error: Error) => {
        toast.error(error.message || 'Failed to update email verification')
      },
    })
  }

  const handleVerifyPhone = () => {
    if (!hasPhone || updatePhoneVerification.isPending) return
    updatePhoneVerification.mutate(!phoneVerified, {
      onSuccess: () => {
        toast.success(
          `Phone has been ${phoneVerified ? 'unverified' : 'verified'}`,
        )
      },
      onError: (error: Error) => {
        toast.error(error.message || 'Failed to update phone verification')
      },
    })
  }

  const handleToggleBlock = () => {
    if (updateStatus.isPending) return
    updateStatus.mutate(isBlocked ? true : false, {
      onSuccess: () => {
        toast.success(
          `Account ${isBlocked ? 'unblocked' : 'blocked'} successfully`,
        )
      },
      onError: (error: Error) => {
        toast.error(error.message || 'Failed to update account status')
      },
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
          <ContextMenuItem onSelect={handleUpdateUser}>
            <ContextMenuIcon icon={Pencil} />
            Update
          </ContextMenuItem>
          <ContextMenuItem onSelect={handleAddPreferences}>
            <ContextMenuIcon icon={Plus} />
            Add preferences
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={BadgeCheck} />
              Verify account
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem
                onSelect={handleVerifyEmail}
                disabled={!hasEmail || updateEmailVerification.isPending}
              >
                <ContextMenuIcon icon={Mail} />
                {emailVerified ? 'Unverify email' : 'Verify email'}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={handleVerifyPhone}
                disabled={!hasPhone || updatePhoneVerification.isPending}
              >
                <ContextMenuIcon icon={Phone} />
                {phoneVerified ? 'Unverify phone' : 'Verify phone'}
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuItem onSelect={handleToggleBlock}>
            <ContextMenuIcon icon={ShieldOff} />
            {isBlocked ? 'Unblock account' : 'Block account'}
          </ContextMenuItem>
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
              {hasEmail && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Email', user.email)}
                >
                  <ContextMenuIcon icon={Mail} />
                  Copy email
                </ContextMenuItem>
              )}
              {hasPhone && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Phone', user.phone)}
                >
                  <ContextMenuIcon icon={Phone} />
                  Copy phone
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
