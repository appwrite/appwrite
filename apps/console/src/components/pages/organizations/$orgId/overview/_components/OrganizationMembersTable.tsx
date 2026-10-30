import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  CheckCircle2,
  Copy,
  ExternalLink,
  FileJson,
  Link2,
  Mail,
  Square,
  Trash2,
  XCircle,
} from '@/lib/icons'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { PhotoAvatar } from '@/components/global/shared/Avatar'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import {
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { Badge } from '@/components/ui/badge'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { OrgMemberContextMenu } from '@/components/pages/organizations/$orgId/overview/_components/OrgMemberContextMenu'
import { OrgMemberManageMenuTooltip } from '@/components/pages/organizations/$orgId/overview/_components/OrgMemberManageMenuTooltip'
import { OrgMemberRemoveDialog } from '@/components/pages/organizations/$orgId/overview/_components/OrgMemberRemoveDialog'
import {
  canRemoveOrgTeamMember,
  isCurrentOrgMember,
} from '@/components/pages/organizations/$orgId/overview/_components/org-member-actions'
import { OrgRoleBadge, orgMembershipRoleDisplay } from '@/components/pages/organizations/$orgId/overview/_components/OrgRoleBadge'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canInviteOrgMember } from '@/lib/console-access-checks'
import { parseProjectAccess } from '@/lib/console-project-roles'
import { useT } from '@/lib/i18n/translate'
import {
  useOrganizationScopes,
  useRemoveTeamMember,
  useResendMembershipInvite,
} from '@/lib/react-query/hooks'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import type { TeamMember } from '@/lib/utils/mock-data'

const TABLE_HEAD_CLASSNAME =
  'px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground'

export function OrganizationMembersTable({
  organizationId,
  members,
  projectNameById,
  showRoles,
  showProjectRoles,
  showMfa,
}: {
  organizationId: string
  members: TeamMember[]
  projectNameById: Map<string, string>
  showRoles: boolean
  showProjectRoles: boolean
  showMfa: boolean
}) {
  const t = useT()
  const queryClient = useQueryClient()
  const { account } = useAuth()
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(organizationId)
  const canManageMembers = canInviteOrgMember(access, features)
  const manageDisabledTooltip = t("You don't have permission to manage members.")
  const resendInviteMutation = useResendMembershipInvite(organizationId)
  const removeMemberMutation = useRemoveTeamMember(organizationId)
  const [memberToRemove, setMemberToRemove] = useState<TeamMember | null>(null)

  const refetchMemberships = () =>
    queryClient.refetchQueries({
      queryKey: ['memberships', 'organization', organizationId],
    })

  const handleResend = async (member: TeamMember) => {
    const roles =
      member.roles && member.roles.length > 0 ? member.roles : [member.role]
    try {
      await resendInviteMutation.mutateAsync({
        membershipId: member.membershipId || member.$id,
        email: member.userEmail,
        roles,
      })
      await refetchMemberships()
      toast.success(t('Invitation resent successfully'))
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, t('Failed to resend invitation')))
    }
  }

  const handleConfirmRemove = async () => {
    if (!memberToRemove) return
    const isInvite = memberToRemove.status === 'pending'
    try {
      await removeMemberMutation.mutateAsync(
        memberToRemove.membershipId || memberToRemove.$id,
      )
      await refetchMemberships()
      toast.success(
        isInvite
          ? t('Invitation cancelled successfully')
          : t('Member removed successfully'),
      )
      setMemberToRemove(null)
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, t('Failed to remove member')))
    }
  }

  return (
    <TooltipProvider delayDuration={0}>
      <Table withScrollContainer={false}>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className={TABLE_HEAD_CLASSNAME}>{t('Member')}</TableHead>
            {showRoles ? (
              <TableHead className={`${TABLE_HEAD_CLASSNAME} text-center`}>
                {t('Role')}
              </TableHead>
            ) : null}
            {showProjectRoles ? (
              <TableHead
                className={`${TABLE_HEAD_CLASSNAME} hidden text-center md:table-cell`}
              >
                {t('Projects')}
              </TableHead>
            ) : null}
            {showMfa ? (
              <TableHead
                className={`${TABLE_HEAD_CLASSNAME} hidden text-center sm:table-cell`}
              >
                {t('MFA')}
              </TableHead>
            ) : null}
            <TableHead
              className={`${TABLE_HEAD_CLASSNAME} hidden text-end sm:table-cell`}
            >
              {t('Joined')}
            </TableHead>
            <TableHead
              className={`${TABLE_HEAD_CLASSNAME} w-[40px] text-end`}
            />
          </TableRow>
        </TableHeader>
        <TableBody>
          {members.map((member) => {
            const memberProjectAccess = showProjectRoles
              ? parseProjectAccess(member.roles)
              : []
            const isProjectScoped = memberProjectAccess.length > 0
            const projectLabel = (projectId: string) =>
              projectNameById.get(projectId) ?? projectId
            const membersHref = buildConsoleUrl(
              `/organizations/${organizationId}/settings/members`,
            )
            const displayName = member.userName || member.userEmail || '-'
            const hasName =
              !!member.userName && member.userName !== member.userEmail
            const isPending = member.status === 'pending'
            const isCurrentMember = isCurrentOrgMember(member, account)
            const canRemove = canRemoveOrgTeamMember(member, {
              canManageMembers,
              isCurrentMember,
            })
            const openRemove = () => setMemberToRemove(member)

            return (
              <OrgMemberContextMenu
                key={member.$id}
                orgId={organizationId}
                member={member}
                canManageMembers={canManageMembers}
                canRemove={canRemove}
                showDisabledManageActions
                manageDisabledTooltip={manageDisabledTooltip}
                resendPending={resendInviteMutation.isPending}
                onResend={() => {
                  void handleResend(member)
                }}
                onRemove={openRemove}
              >
                <TableRow className="border-b border-border/50 transition-colors hover:bg-muted/30">
                  <TableCell className="px-4 py-3">
                    <div className="flex min-w-0 items-center gap-3">
                      <PhotoAvatar
                        userId={member.userId}
                        name={displayName}
                        size="sm"
                        className="shrink-0"
                      />
                      <div className="min-w-0 flex-1">
                        <div className="flex min-w-0 items-center gap-2">
                          <p className="min-w-0 truncate text-[13px] font-medium text-foreground">
                            {displayName}
                          </p>
                          {isPending ? (
                            <Badge
                              variant="pending"
                              className="shrink-0 text-[10px]"
                            >
                              {t('Pending')}
                            </Badge>
                          ) : null}
                        </div>
                        <p className="mt-0.5 truncate text-[12px] text-muted-foreground">
                          {member.userEmail || '-'}
                        </p>
                      </div>
                    </div>
                  </TableCell>
                  {showRoles ? (
                    <TableCell className="px-4 py-3">
                      <div className="flex flex-nowrap items-center justify-center gap-1 whitespace-nowrap">
                        {isProjectScoped ? (
                          <span className="text-[12px] text-muted-foreground">
                            {t('Per project')}
                          </span>
                        ) : (
                          <OrgRoleBadge role={member.role} />
                        )}
                      </div>
                    </TableCell>
                  ) : null}
                  {showProjectRoles ? (
                    <TableCell className="hidden px-4 py-3 md:table-cell">
                      <div className="flex items-center justify-center">
                        {!isProjectScoped ? (
                          <span className="text-[12px] text-muted-foreground">
                            {t('All projects')}
                          </span>
                        ) : (
                          <Popover>
                            <PopoverTrigger asChild>
                              <button
                                type="button"
                                className="inline-flex cursor-pointer items-center rounded-md px-1.5 py-0.5 text-[12px] text-foreground hover:bg-muted/60"
                              >
                                {`${memberProjectAccess.length} ${t('projects')}`}
                              </button>
                            </PopoverTrigger>
                            <PopoverContent align="center" className="w-64 p-0">
                              <p className="border-b border-border px-3 py-2 text-[11px] font-medium text-muted-foreground">
                                {t('Project access')}
                              </p>
                              <div className="max-h-64 overflow-y-auto py-1">
                                {memberProjectAccess.map((row) => (
                                  <div
                                    key={row.projectId}
                                    className="flex items-center justify-between gap-3 px-3 py-1.5"
                                  >
                                    <span
                                      className="min-w-0 truncate text-[12px] text-foreground"
                                      title={projectLabel(row.projectId)}
                                    >
                                      {projectLabel(row.projectId)}
                                    </span>
                                    <span className="shrink-0 text-[11px] text-muted-foreground">
                                      {t(
                                        orgMembershipRoleDisplay(row.roleName)
                                          .label,
                                      )}
                                    </span>
                                  </div>
                                ))}
                              </div>
                            </PopoverContent>
                          </Popover>
                        )}
                      </div>
                    </TableCell>
                  ) : null}
                  {showMfa ? (
                    <TableCell className="hidden px-4 py-3 sm:table-cell">
                      <div className="flex items-center justify-center">
                        {isPending ? (
                          <span className="text-[12px] text-muted-foreground/50">
                            -
                          </span>
                        ) : member.mfaEnabled ? (
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <div className="flex items-center justify-center">
                                <CheckCircle2 className="h-4 w-4 text-green-600 dark:text-green-400" />
                              </div>
                            </TooltipTrigger>
                            <TooltipContent>
                              <p className="text-xs">
                                {t('Multi-factor authentication enabled')}
                              </p>
                            </TooltipContent>
                          </Tooltip>
                        ) : (
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <div className="flex items-center justify-center">
                                <XCircle className="h-4 w-4 text-muted-foreground/40" />
                              </div>
                            </TooltipTrigger>
                            <TooltipContent>
                              <p className="text-xs">
                                {t('Multi-factor authentication not enabled')}
                              </p>
                            </TooltipContent>
                          </Tooltip>
                        )}
                      </div>
                    </TableCell>
                  ) : null}
                  <TableCell className="hidden px-4 py-3 sm:table-cell">
                    <div className="text-end">
                      {isPending ? (
                        <span className="text-[12px] italic text-muted-foreground/70">
                          {t('Invited')}
                        </span>
                      ) : member.joinedAt ? (
                        <DateTooltip
                          date={new Date(member.joinedAt)}
                          className="font-mono text-[12px] text-muted-foreground"
                        />
                      ) : (
                        <span className="text-[12px] text-muted-foreground">
                          -
                        </span>
                      )}
                    </div>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <div className="flex items-center justify-end">
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <RowActionsMenuTrigger />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                          {isPending ? (
                            <>
                              <OrgMemberManageMenuTooltip
                                enabled={!canManageMembers}
                                label={manageDisabledTooltip}
                              >
                                <DropdownMenuItem
                                  disabled={
                                    !canManageMembers ||
                                    resendInviteMutation.isPending
                                  }
                                  onClick={() => {
                                    void handleResend(member)
                                  }}
                                >
                                  <MenuItemContent icon={Mail}>
                                    {t('Resend')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                              </OrgMemberManageMenuTooltip>
                              <DropdownMenuSeparator />
                            </>
                          ) : null}
                          <DropdownMenuSub>
                            <DropdownMenuSubTrigger>
                              <MenuItemIcon icon={Copy} />
                              {t('Copy')}
                            </DropdownMenuSubTrigger>
                            <DropdownMenuSubContent>
                              <DropdownMenuItem
                                onClick={() =>
                                  copyToClipboard(
                                    'ID',
                                    member.membershipId || member.$id,
                                  )
                                }
                              >
                                <MenuItemContent icon={Copy}>
                                  {t('Copy ID')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                              {hasName ? (
                                <DropdownMenuItem
                                  onClick={() =>
                                    copyToClipboard('Name', member.userName)
                                  }
                                >
                                  <MenuItemContent icon={Copy}>
                                    {t('Copy name')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                              ) : null}
                              <DropdownMenuItem
                                onClick={() =>
                                  copyToClipboard('Link', membersHref)
                                }
                              >
                                <MenuItemContent icon={Link2}>
                                  {t('Copy link')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                onClick={() =>
                                  void copyResourceAsJson(() => member)
                                }
                              >
                                <MenuItemContent icon={FileJson}>
                                  {t('Copy as JSON')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                            </DropdownMenuSubContent>
                          </DropdownMenuSub>
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            onClick={() => openInNewTab(membersHref)}
                          >
                            <MenuItemContent icon={ExternalLink}>
                              {t('Open in new tab')}
                            </MenuItemContent>
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            onClick={() => openInNewWindow(membersHref)}
                          >
                            <MenuItemContent icon={Square}>
                              {t('Open in new window')}
                            </MenuItemContent>
                          </DropdownMenuItem>
                          <DropdownMenuSeparator />
                          <OrgMemberManageMenuTooltip
                            enabled={!canRemove}
                            label={manageDisabledTooltip}
                          >
                            <DropdownMenuItem
                              disabled={!canRemove}
                              onClick={openRemove}
                            >
                              <MenuItemContent icon={Trash2}>
                                {isPending
                                  ? t('Cancel invitation')
                                  : t('Remove')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                          </OrgMemberManageMenuTooltip>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </TableCell>
                </TableRow>
              </OrgMemberContextMenu>
            )
          })}
        </TableBody>
      </Table>
      <OrgMemberRemoveDialog
        open={memberToRemove != null}
        onOpenChange={(open) => {
          if (!open) setMemberToRemove(null)
        }}
        member={memberToRemove}
        isPending={removeMemberMutation.isPending}
        onConfirm={() => {
          void handleConfirmRemove()
        }}
      />
    </TooltipProvider>
  )
}
