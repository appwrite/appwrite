import { useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { toast } from 'sonner'
import {
  Copy,
  ExternalLink,
  FileJson,
  LayoutList,
  Link2,
  Square,
  Trash2,
  User2,
  Users,
} from 'lucide-react'
import { useDeleteTeamMembership } from '@/lib/react-query/hooks'
import type { Models } from '@appwrite.io/console'
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
import { useT } from '@/lib/i18n/translate'

interface MembershipContextMenuProps {
  projectId: string
  membership: Models.Membership
  children: React.ReactNode
  onOpenMembership?: () => void
  onDeleted?: () => void
}

export function MembershipContextMenu({
  projectId,
  membership,
  children,
  onOpenMembership,
  onDeleted,
}: MembershipContextMenuProps) {
  const t = useT()
  const navigate = useNavigate()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const deleteMutation = useDeleteTeamMembership(projectId, membership.teamId)

  const membershipHref = buildConsoleUrl(
    `/projects/${projectId}/auth/teams/${membership.teamId}/members`,
  )

  const hasUserName = !!membership.userName
  const hasTeamName = !!membership.teamName

  const handleDelete = () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteDialogOpen(false))
    deleteMutation.mutate(membership.$id, {
      onSuccess: () => {
        toast.success(t('Membership removed'))
        onDeleted?.()
      },
      onError: (error: Error) => {
        toast.error(error.message || t('Failed to remove membership'))
      },
    })
  }

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-56">
          <ContextMenuItem onSelect={() => onOpenMembership?.()}>
            <ContextMenuIcon icon={LayoutList} />
            {t('Overview')}
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() =>
              navigate({
                to: '/projects/$projectId/auth/teams/$teamId',
                params: { projectId, teamId: membership.teamId },
              })
            }
          >
            <ContextMenuIcon icon={Users} />
            {t('Team')}
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() =>
              navigate({
                to: '/projects/$projectId/auth/users/$userId',
                params: { projectId, userId: membership.userId },
              })
            }
          >
            <ContextMenuIcon icon={User2} />
            {t('User')}
          </ContextMenuItem>
          <ContextMenuSeparator />

          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              {t('Copy')}
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem
                onSelect={() => copyToClipboard('ID', membership.$id)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy ID')}
              </ContextMenuItem>
              {hasUserName && (
                <ContextMenuItem
                  onSelect={() =>
                    copyToClipboard('User name', membership.userName)
                  }
                >
                  <ContextMenuIcon icon={User2} />
                  {t('Copy user name')}
                </ContextMenuItem>
              )}
              {hasTeamName && (
                <ContextMenuItem
                  onSelect={() =>
                    copyToClipboard('Team name', membership.teamName)
                  }
                >
                  <ContextMenuIcon icon={Users} />
                  {t('Copy team name')}
                </ContextMenuItem>
              )}
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', membershipHref)}
              >
                <ContextMenuIcon icon={Link2} />
                {t('Copy link')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  void copyResourceAsJson(() => membership)
                }
              >
                <ContextMenuIcon icon={FileJson} />
                {t('Copy as JSON')}
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>

          <ContextMenuSeparator />
          <ContextMenuItem onSelect={() => openInNewTab(membershipHref)}>
            <ContextMenuIcon icon={ExternalLink} />
            {t('Open in new tab')}
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => openInNewWindow(membershipHref)}>
            <ContextMenuIcon icon={Square} />
            {t('Open in new window')}
          </ContextMenuItem>

          <ContextMenuSeparator />
          <ContextMenuItem
            onSelect={() =>
              openDialogAfterOverlayCloses(() => setDeleteDialogOpen(true))
            }
          >
            <ContextMenuIcon icon={Trash2} />
            {t('Delete')}
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Remove from team')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Are you sure you want to remove this membership? This action cannot be undone.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deleteMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={handleDelete}
              disabled={deleteMutation.isPending}
            >
              {t('Remove')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
