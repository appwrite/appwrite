import { toast } from 'sonner'
import {
  Copy,
  ExternalLink,
  FileJson,
  Link2,
  Mail,
  Square,
  Trash2,
  UserCog,
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
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { useResendMembershipInvite } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import type { TeamMember } from '@/lib/utils/mock-data'

interface OrgMemberContextMenuProps {
  orgId: string
  member: TeamMember
  canManageMembers: boolean
  onUpdate?: () => void
  onRemove?: () => void
  children: React.ReactNode
}

export function OrgMemberContextMenu({
  orgId,
  member,
  canManageMembers,
  onUpdate,
  onRemove,
  children,
}: OrgMemberContextMenuProps) {
  const t = useT()
  const resendInviteMutation = useResendMembershipInvite(orgId)

  const membershipId = member.membershipId || member.$id
  const membersHref = buildConsoleUrl(
    `/organizations/${orgId}/settings/members`,
  )
  const hasName =
    !!member.userName && member.userName !== member.userEmail
  const isPending = member.status === 'pending'

  const handleResend = () => {
    const roles =
      member.roles && member.roles.length > 0 ? member.roles : [member.role]

    resendInviteMutation.mutate(
      {
        membershipId,
        email: member.userEmail,
        roles,
      },
      {
        onSuccess: () => {
          toast.success(t('Invitation resent successfully'))
        },
        onError: (error: Error) => {
          toast.error(error.message || t('Failed to resend invitation'))
        },
      },
    )
  }

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        {canManageMembers && isPending && (
          <>
            <ContextMenuItem
              disabled={resendInviteMutation.isPending}
              onSelect={handleResend}
            >
              <ContextMenuIcon icon={Mail} />
              {t('Resend')}
            </ContextMenuItem>
            <ContextMenuSeparator />
          </>
        )}
        {canManageMembers && !isPending && onUpdate && (
          <>
            <ContextMenuItem onSelect={onUpdate}>
              <ContextMenuIcon icon={UserCog} />
              {t('Update')}
            </ContextMenuItem>
            <ContextMenuSeparator />
          </>
        )}

        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            {t('Copy')}
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem
              onSelect={() => copyToClipboard('ID', membershipId)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy ID')}
            </ContextMenuItem>
            {hasName && (
              <ContextMenuItem
                onSelect={() => copyToClipboard('Name', member.userName)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy name')}
              </ContextMenuItem>
            )}
            <ContextMenuItem
              onSelect={() => copyToClipboard('Link', membersHref)}
            >
              <ContextMenuIcon icon={Link2} />
              {t('Copy link')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() => void copyResourceAsJson(() => member)}
            >
              <ContextMenuIcon icon={FileJson} />
              {t('Copy as JSON')}
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>

        <ContextMenuSeparator />
        <ContextMenuItem onSelect={() => openInNewTab(membersHref)}>
          <ContextMenuIcon icon={ExternalLink} />
          {t('Open in new tab')}
        </ContextMenuItem>
        <ContextMenuItem onSelect={() => openInNewWindow(membersHref)}>
          <ContextMenuIcon icon={Square} />
          {t('Open in new window')}
        </ContextMenuItem>

        {canManageMembers && onRemove && (
          <>
            <ContextMenuSeparator />
            <ContextMenuItem onSelect={onRemove}>
              <ContextMenuIcon icon={Trash2} />
              {isPending ? t('Cancel invitation') : t('Remove')}
            </ContextMenuItem>
          </>
        )}
      </ContextMenuContent>
    </ContextMenu>
  )
}
