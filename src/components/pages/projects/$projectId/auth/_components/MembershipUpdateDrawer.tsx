import { useState, useEffect } from 'react'
import { Link } from '@tanstack/react-router'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  useUpdateTeamMembership,
  useDeleteTeamMembership,
} from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import { Plus, X, Trash2, ChevronRight } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'

export interface MembershipUpdateDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  membership: Models.Membership | null
  projectId: string
  /** 'user' = user's memberships tab (title shows team name); 'team' = team's members tab (title shows user name) */
  context: 'user' | 'team'
}

export function MembershipUpdateDrawer({
  open,
  onOpenChange,
  membership,
  projectId,
  context,
}: MembershipUpdateDrawerProps) {
  const t = useT()
  const teamId = membership?.teamId ?? ''
  const [roles, setRoles] = useState<string[]>([])
  const [roleInput, setRoleInput] = useState('')
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false)

  const updateMutation = useUpdateTeamMembership(projectId, teamId)
  const deleteMutation = useDeleteTeamMembership(projectId, teamId)

  useEffect(() => {
    if (open && membership) {
      setRoles(membership.roles ?? [])
      setRoleInput('')
      setDeleteConfirmOpen(false)
    }
  }, [open, membership])

  const handleOpenChange = (newOpen: boolean) => {
    if (!updateMutation.isPending && !deleteMutation.isPending) {
      onOpenChange(newOpen)
      if (!newOpen) setDeleteConfirmOpen(false)
    }
  }

  const handleAddRole = () => {
    if (roleInput.trim() && !roles.includes(roleInput.trim())) {
      setRoles([...roles, roleInput.trim()])
      setRoleInput('')
    }
  }

  const handleRemoveRole = (roleToRemove: string) => {
    setRoles(roles.filter((r) => r !== roleToRemove))
  }

  const handleUpdate = () => {
    if (!membership || roles.length === 0) return
    updateMutation.mutate(
      { membershipId: membership.$id, roles },
      {
        onSuccess: () => {
          toast.success(t('Membership updated'))
          onOpenChange(false)
        },
        onError: (error: Error) => {
          toast.error(error.message || t('Failed to update membership'))
        },
      },
    )
  }

  const handleDelete = () => {
    if (!membership) return
    deleteMutation.mutate(membership.$id, {
      onSuccess: () => {
        toast.success(t('Membership removed'))
        setDeleteConfirmOpen(false)
        onOpenChange(false)
      },
      onError: (error: Error) => {
        toast.error(error.message || t('Failed to remove membership'))
      },
    })
  }

  const isPending = updateMutation.isPending || deleteMutation.isPending

  return (
    <>
      <BaseDrawer
        open={open}
        onOpenChange={handleOpenChange}
        title={t('Update membership')}
        maxWidth="sm:max-w-lg"
      >
        <>
          <div className="border-t border-border shrink-0" />

          <div className="flex flex-1 flex-col min-h-0">
            <div className="flex-1 overflow-y-auto">
              <div className="px-6 py-6">
                <div className="space-y-5">
                  {/* Membership metadata */}
                  {membership && (
                    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                      <div className="px-6 py-3">
                        <h3 className="text-[15px] font-semibold text-foreground">
                          {t('Membership')}
                        </h3>
                      </div>
                      <div className="border-t border-border" />
                      <div className="px-6 py-3 grid grid-cols-2 gap-x-4 gap-y-3">
                        <div className="min-w-0">
                          <p className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider">
                            {t('Status')}
                          </p>
                          <div className="mt-0.5 flex flex-col gap-0.5">
                            <Badge
                              variant={
                                membership.confirm ? 'active' : 'pending'
                              }
                              className="text-[10px] shrink-0 w-fit"
                            >
                              {membership.confirm ? t('Active') : t('Pending')}
                            </Badge>
                            {!membership.confirm && (
                              <span className="text-[11px] text-muted-foreground">
                                {t('Invitation not yet accepted')}
                              </span>
                            )}
                          </div>
                        </div>
                        <div className="min-w-0">
                          <p className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider">
                            {t('Membership ID')}
                          </p>
                          <p className="mt-0.5">
                            <CopyableId id={membership.$id} size="xs" />
                          </p>
                        </div>
                        <div className="min-w-0">
                          <p className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider">
                            {t('Created')}
                          </p>
                          <p className="mt-0.5 text-[13px] text-foreground">
                            <DateTooltip date={membership.$createdAt} />
                          </p>
                        </div>
                        <div className="min-w-0">
                          <p className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider">
                            {t('Invited')}
                          </p>
                          <p className="mt-0.5 text-[13px] text-foreground">
                            {membership.invited ? (
                              <DateTooltip date={membership.invited} />
                            ) : (
                              <span className="text-muted-foreground">-</span>
                            )}
                          </p>
                        </div>
                        <div className="min-w-0">
                          <p className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider">
                            {t('Joined')}
                          </p>
                          <p className="mt-0.5 text-[13px] text-foreground">
                            {membership.joined ? (
                              <DateTooltip date={membership.joined} />
                            ) : (
                              <span className="text-muted-foreground">-</span>
                            )}
                          </p>
                        </div>
                        <div className="min-w-0">
                          <p className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider">
                            {t('Updated')}
                          </p>
                          <p className="mt-0.5 text-[13px] text-foreground">
                            <DateTooltip date={membership.$updatedAt} />
                          </p>
                        </div>
                      </div>
                    </div>
                  )}

                  {/* Reference cards: team and user with links to their detail pages */}
                  {membership && projectId && (
                    <div className="space-y-3">
                      <Link
                        to="/projects/$projectId/auth/teams/$teamId"
                        params={{ projectId, teamId: membership.teamId }}
                        className="flex items-center gap-3 rounded-xl border border-border bg-card/50 px-4 py-3 transition-colors hover:bg-muted/50"
                      >
                        <InitialsAvatar name={membership.teamName} size="md" />
                        <div className="flex-1 min-w-0">
                          <p className="text-[13px] font-medium text-foreground truncate">
                            {membership.teamName || t('Team')}
                          </p>
                          <p className="text-[12px] text-muted-foreground truncate">
                            {t('Team')}
                          </p>
                        </div>
                        <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                      </Link>
                      <Link
                        to="/projects/$projectId/auth/users/$userId"
                        params={{ projectId, userId: membership.userId }}
                        className="flex items-center gap-3 rounded-xl border border-border bg-card/50 px-4 py-3 transition-colors hover:bg-muted/50"
                      >
                        <InitialsAvatar
                          name={
                            membership.userName ||
                            membership.userEmail ||
                            'User'
                          }
                          size="md"
                        />
                        <div className="flex-1 min-w-0">
                          <p className="text-[13px] font-medium text-foreground truncate">
                            {membership.userName ||
                              membership.userEmail ||
                              t('User')}
                          </p>
                          <p className="text-[12px] text-muted-foreground truncate">
                            {t('User')}
                          </p>
                        </div>
                        <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                      </Link>
                    </div>
                  )}

                  <div className="space-y-2">
                    <Label htmlFor="membership-roles">{t('Roles')}</Label>
                    <div className="flex gap-2">
                      <Input
                        id="membership-roles"
                        value={roleInput}
                        onChange={(e) => setRoleInput(e.target.value)}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter' && roleInput.trim()) {
                            e.preventDefault()
                            handleAddRole()
                          }
                        }}
                        placeholder={t('Add role')}
                        autoComplete="off"
                      />
                      <Button
                        type="button"
                        variant="outline"
                        onClick={handleAddRole}
                        disabled={!roleInput.trim()}
                      >
                        <Plus className="h-4 w-4" />
                      </Button>
                    </div>
                    {roles.length > 0 && (
                      <div className="flex flex-wrap gap-1.5">
                        {roles.map((role) => (
                          <Badge
                            key={role}
                            variant="info"
                            className="text-[10px] shrink-0 gap-1 pe-1"
                          >
                            {role}
                            <button
                              type="button"
                              onClick={() => handleRemoveRole(role)}
                              className="hover:text-foreground rounded p-0.5"
                            >
                              <X className="h-3 w-3" />
                            </button>
                          </Badge>
                        ))}
                      </div>
                    )}
                    <p className="text-[12px] text-muted-foreground">
                      {t('Roles are used to manage access permissions.')}{' '}
                      <DocsRouteLink className="link-neutral" href="/docs/advanced/platform/permissions">
                        {t('Learn more about permissions')}
                      </DocsRouteLink>
                    </p>
                  </div>

                  {/* Remove from team card - same pattern as other delete cards */}
                  <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden mt-6">
                    <div className="px-6 py-4">
                      <h3 className="text-[15px] font-semibold text-foreground">
                        {t('Remove from team')}
                      </h3>
                    </div>
                    <div className="border-t border-destructive/20" />
                    <div className="px-6 py-4">
                      <p className="text-[13px] text-muted-foreground">
                        {t(
                          'Remove this membership. The user will lose access to this team. This action cannot be undone.',
                        )}
                      </p>
                    </div>
                    <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
                      <Button
                        type="button"
                        variant="destructive"
                        size="sm"
                        className="h-9 text-[13px]"
                        onClick={() => setDeleteConfirmOpen(true)}
                        disabled={isPending}
                      >
                        <Trash2 className="me-1.5 h-4 w-4" />
                        {t('Remove from team')}
                      </Button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div className="flex-shrink-0 flex items-center justify-start gap-2 border-t border-border bg-muted/30 px-6 py-4">
              <Button
                type="button"
                onClick={handleUpdate}
                disabled={roles.length === 0 || isPending}
              >
                {t('Update')}
              </Button>
              <Button
                type="button"
                variant="outline"
                onClick={() => handleOpenChange(false)}
                disabled={isPending}
              >
                {t('Cancel')}
              </Button>
            </div>
          </div>
        </>
      </BaseDrawer>

      <Dialog open={deleteConfirmOpen} onOpenChange={setDeleteConfirmOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Remove from team')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to remove')}{' '}
              <strong>
                {context === 'team'
                  ? membership?.userName ||
                    membership?.userEmail ||
                    t('this member')
                  : membership?.userName ||
                    membership?.userEmail ||
                    t('this user')}
                {' · '}
                {membership?.teamName || t('this team')}
              </strong>
              ? {t('This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setDeleteConfirmOpen(false)}
              disabled={deleteMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="button"
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
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
