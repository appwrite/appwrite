import { useState, useMemo } from 'react'
import {
  X,
  Plus,
  Mail,
  Shield,
  Code,
  Edit,
  Eye,
  CreditCard,
} from 'lucide-react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
} from '@/components/ui/select'
import { UpgradePlanLink } from '@/components/global/shared/UpgradePlanLink'
import { AdditionalChargeAlert } from '@/components/global/shared/AdditionalChargeAlert'
import { wouldIncurPlanAddonCharge } from '@/lib/billing/plan-addon-charge'
import { cn } from '@/lib/utils'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Label } from '@/components/ui/label'
import {
  ProjectAccessSelector,
  DEFAULT_PROJECT_ROLE,
} from './_components/ProjectAccessSelector'
import {
  buildProjectRole,
  type ProjectAccessEntry,
} from '@/lib/console-project-roles'
import type { Models } from '@appwrite.io/console'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { sdk } from '@/lib/appwrite/sdk'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'

interface InviteMember {
  email: string
  role: 'owner' | 'developer' | 'editor' | 'analyst' | 'billing'
}

/**
 * Whether access is granted across the whole organization or per project.
 * Per-project access is a paid capability, gated on the plan flag below.
 */
type AccessType = 'all' | 'specific'

interface InviteMembersDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  organizationId: string
  currentMemberCount: number
  memberLimit: number | null
  organizationPlan?: Models.BillingPlan | null
  /** Called after invites are successfully sent */
  onSuccess?: () => void
}

const ROLE_OPTIONS = [
  {
    value: 'owner',
    label: 'Owner',
    icon: Shield,
    description: 'Full control over all aspects including team and billing.',
  },
  {
    value: 'developer',
    label: 'Developer',
    icon: Code,
    description: 'All resources except team management and billing writes.',
  },
  {
    value: 'editor',
    label: 'Editor',
    icon: Edit,
    description: 'Can modify most resources but not critical backend.',
  },
  {
    value: 'analyst',
    label: 'Analyst',
    icon: Eye,
    description: 'Read-only access across all resources.',
  },
  {
    value: 'billing',
    label: 'Billing',
    icon: CreditCard,
    description: 'Billing and payment management only.',
  },
] as const

export function InviteMembersDialog({
  open,
  onOpenChange,
  organizationId,
  currentMemberCount,
  memberLimit,
  organizationPlan,
  onSuccess,
}: InviteMembersDialogProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const { features } = useConsoleProfile()
  const [invites, setInvites] = useState<InviteMember[]>([
    { email: '', role: 'owner' },
  ])
  const [touchedFields, setTouchedFields] = useState<Set<number>>(new Set())
  const [accessType, setAccessType] = useState<AccessType>('all')
  const [projectAccess, setProjectAccess] = useState<ProjectAccessEntry[]>([])

  const supportsProjectRoles = Boolean(
    features.orgRoles && organizationPlan?.supportsProjectSpecificRoles,
  )
  // Project access applies to every invite in this dialog — the rows describe
  // the access being granted, not the individual recipient.
  const useProjectAccess = supportsProjectRoles && accessType === 'specific'

  const validProjectAccess = useMemo(
    () => projectAccess.filter((row) => row.projectId && row.roleName),
    [projectAccess],
  )

  const buildRoles = (invite: InviteMember): string[] => {
    if (useProjectAccess) {
      return validProjectAccess.map((row) =>
        buildProjectRole(row.projectId, row.roleName),
      )
    }
    // When orgRoles is disabled, all members are owners
    return [features.orgRoles ? invite.role : 'owner']
  }

  // Check if we're at or near the limit
  // memberLimit === null means unlimited, memberLimit === 0 might also mean unlimited
  const canAddMore = useMemo(() => {
    if (memberLimit === null || memberLimit === 0) return true // Unlimited
    return currentMemberCount + invites.length < memberLimit
  }, [memberLimit, currentMemberCount, invites.length])

  const remainingSlots = useMemo(() => {
    if (memberLimit === null || memberLimit === 0) return null // Unlimited
    return Math.max(0, memberLimit - currentMemberCount - invites.length)
  }, [memberLimit, currentMemberCount, invites.length])

  // Validate email format
  const isValidEmail = (email: string) => {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)
  }

  const validInviteCount = useMemo(
    () =>
      invites.filter(
        (invite) =>
          invite.email.trim() !== '' && isValidEmail(invite.email.trim()),
      ).length,
    [invites],
  )

  const additionalMemberCharge = useMemo(() => {
    if (!features.billing || !organizationPlan || validInviteCount === 0) {
      return null
    }
    return wouldIncurPlanAddonCharge(
      organizationPlan,
      'seats',
      currentMemberCount,
      validInviteCount,
    )
  }, [
    features.billing,
    organizationPlan,
    currentMemberCount,
    validInviteCount,
  ])

  // Check if all invites are valid
  const isValid = useMemo(() => {
    return (
      invites.length > 0 &&
      invites.every(
        (invite) =>
          invite.email.trim() !== '' && isValidEmail(invite.email.trim()),
      ) &&
      canAddMore &&
      // Granting per-project access with no projects selected would send an
      // invite that carries no access at all.
      (!useProjectAccess || validProjectAccess.length > 0)
    )
  }, [invites, canAddMore, useProjectAccess, validProjectAccess])

  // Create membership mutation
  const createMembershipMutation = useMutation({
    mutationFn: async (invite: InviteMember) => {
      const roles = buildRoles(invite)

      // Construct the redirect URL for accepting the invitation
      const acceptUrl = `${window.location.origin}/join`

      return await sdk.forConsole.teams.createMembership({
        teamId: organizationId,
        email: invite.email.trim(),
        roles,
        url: acceptUrl,
      })
    },
  })

  // Handle invite all
  const handleInvite = async () => {
    if (!isValid) return

    try {
      // Invite all members sequentially
      const results = await Promise.allSettled(
        invites.map((invite) => createMembershipMutation.mutateAsync(invite)),
      )

      // Count successes and failures
      const successes = results.filter((r) => r.status === 'fulfilled').length
      const failures = results.filter((r) => r.status === 'rejected').length

      if (successes > 0) {
        toast.success(
          `${t('Successfully invited')} ${successes} ${successes !== 1 ? t('members') : t('member')}`,
        )
        onSuccess?.()
      }

      if (failures > 0) {
        const errors = results
          .filter((r) => r.status === 'rejected')
          .map(
            (r) =>
              (r as PromiseRejectedResult).reason?.message || 'Unknown error',
          )

        toast.error(
          `${t('Failed to invite')} ${failures} ${failures !== 1 ? t('members') : t('member')}: ${errors[0]}`,
        )
      }

      // Invalidate memberships query to refresh the list
      queryClient.invalidateQueries({
        queryKey: ['memberships', 'organization', organizationId],
      })

      // Reset and close
      setInvites([
        { email: '', role: features.orgRoles ? 'developer' : 'owner' },
      ])
      setAccessType('all')
      setProjectAccess([])
      onOpenChange(false)
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : t('Failed to invite members'),
      )
    }
  }

  // Add new invite row
  const handleAddInvite = () => {
    if (
      memberLimit !== null &&
      memberLimit > 0 &&
      currentMemberCount + invites.length >= memberLimit
    ) {
      toast.error(t('Member limit reached'))
      return
    }
    setInvites([
      ...invites,
      { email: '', role: features.orgRoles ? 'developer' : 'owner' },
    ])
  }

  // Remove invite row
  const handleRemoveInvite = (index: number) => {
    if (invites.length === 1) {
      // Keep at least one row, just clear it
      setInvites([
        { email: '', role: features.orgRoles ? 'developer' : 'owner' },
      ])
    } else {
      setInvites(invites.filter((_, i) => i !== index))
    }
  }

  // Update invite
  const handleUpdateInvite = (
    index: number,
    updates: Partial<InviteMember>,
  ) => {
    const newInvites = [...invites]
    newInvites[index] = { ...newInvites[index], ...updates }
    setInvites(newInvites)
  }

  // Reset form when dialog closes
  const handleOpenChange = (newOpen: boolean) => {
    if (!newOpen) {
      setInvites([
        { email: '', role: features.orgRoles ? 'developer' : 'owner' },
      ])
      setTouchedFields(new Set())
      setAccessType('all')
      setProjectAccess([])
    }
    onOpenChange(newOpen)
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-2xl p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Invite Members')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              "Invite organization members to your organization. They'll receive an email invitation to join.",
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0 max-h-[60dvh] overflow-y-auto">
          {/* Member Limit Warning */}
          {memberLimit !== null &&
            memberLimit > 0 &&
            remainingSlots !== null &&
            remainingSlots <= 3 && (
              <div className="mb-4 rounded-lg border border-amber-500/30 bg-amber-500/5 p-3">
                <p className="text-[12px] text-amber-600 dark:text-amber-400">
                  {remainingSlots === 0 ? (
                    <>
                      {t('You have reached your member limit.')}{' '}
                      <UpgradePlanLink orgId={organizationId} />{' '}
                      {t('to invite more members.')}
                    </>
                  ) : (
                    `${t('You have')} ${remainingSlots} ${remainingSlots !== 1 ? t('member slots') : t('member slot')} ${t('remaining.')}`
                  )}
                </p>
              </div>
            )}

          {additionalMemberCharge && (
            <div className="mb-4">
              <AdditionalChargeAlert
                resourceLabel="member"
                pricePerMonth={additionalMemberCharge.pricePerMonth}
                currency={additionalMemberCharge.currency}
                perUnit
              />
            </div>
          )}

          {/* Access scope: whole organization, or named projects only */}
          {supportsProjectRoles && (
            <div className="mb-4 space-y-1.5">
              <Label className="text-[13px] font-medium">{t('Access')}</Label>
              <RadioGroup
                value={accessType}
                onValueChange={(value: AccessType) => {
                  setAccessType(value)
                  if (value === 'specific' && projectAccess.length === 0) {
                    setProjectAccess([
                      { projectId: '', roleName: DEFAULT_PROJECT_ROLE },
                    ])
                  }
                }}
                className="flex flex-row gap-4"
              >
                <div className="flex items-center gap-2">
                  <RadioGroupItem id="access-all" value="all" />
                  <Label
                    htmlFor="access-all"
                    className="cursor-pointer text-[13px] font-normal"
                  >
                    {t('All projects')}
                  </Label>
                </div>
                <div className="flex items-center gap-2">
                  <RadioGroupItem id="access-specific" value="specific" />
                  <Label
                    htmlFor="access-specific"
                    className="cursor-pointer text-[13px] font-normal"
                  >
                    {t('Specific projects')}
                  </Label>
                </div>
              </RadioGroup>
            </div>
          )}

          {useProjectAccess && (
            <div className="mb-4">
              <ProjectAccessSelector
                orgId={organizationId}
                value={projectAccess}
                onChange={setProjectAccess}
              />
              {validProjectAccess.length === 0 && (
                <p className="mt-2 text-[11px] text-muted-foreground">
                  {t('Add at least one project to grant access.')}
                </p>
              )}
            </div>
          )}

          {/* Invite List */}
          <div className="space-y-3">
            {invites.map((invite, index) => {
              const isTouched = touchedFields.has(index)
              const isEmailValid =
                invite.email.trim() === '' || isValidEmail(invite.email.trim())
              const isDuplicate =
                invites.filter(
                  (i, idx) =>
                    i.email.trim() === invite.email.trim() &&
                    idx !== index &&
                    i.email.trim() !== '',
                ).length > 0
              const selectedRole = ROLE_OPTIONS.find(
                (r) => r.value === invite.role,
              )
              const RoleIcon = selectedRole?.icon || Shield
              const showEmailError =
                isTouched &&
                invite.email.trim() !== '' &&
                (!isEmailValid || isDuplicate)

              return (
                <div
                  key={index}
                  className="flex items-start gap-3 rounded-lg border border-border bg-card/50 p-3"
                >
                  {/* Email Input */}
                  <div className="flex-1 space-y-1.5">
                    <div className="relative">
                      <Mail className="absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                      <Input
                        type="email"
                        placeholder="email@example.com"
                        value={invite.email}
                        onChange={(e) =>
                          handleUpdateInvite(index, { email: e.target.value })
                        }
                        onBlur={() => {
                          setTouchedFields((prev) => new Set(prev).add(index))
                        }}
                        className={cn(
                          'h-9 ps-10 text-[13px]',
                          showEmailError
                            ? 'border-red-500/50 focus:border-red-500/50'
                            : '',
                        )}
                      />
                    </div>
                    {showEmailError && (
                      <>
                        {!isEmailValid && (
                          <p className="text-[11px] text-red-500">
                            {t('Invalid email address')}
                          </p>
                        )}
                        {isDuplicate && isEmailValid && (
                          <p className="text-[11px] text-red-500">
                            {t('Duplicate email')}
                          </p>
                        )}
                      </>
                    )}
                  </div>

                  {/* Role Select - hidden when orgRoles disabled (all members are
                      owners), and when access is per project (the role is set
                      per project row instead) */}
                  {features.orgRoles && !useProjectAccess && (
                    <div className="w-36 shrink-0">
                      <Select
                        value={invite.role}
                        onValueChange={(
                          value:
                            | 'owner'
                            | 'developer'
                            | 'editor'
                            | 'analyst'
                            | 'billing',
                        ) => handleUpdateInvite(index, { role: value })}
                      >
                        <SelectTrigger className="h-9 text-[13px] w-full">
                          <div className="flex items-center gap-1.5 min-w-0">
                            <RoleIcon className="h-3.5 w-3.5 shrink-0" />
                            <span className="truncate">
                              {selectedRole ? t(selectedRole.label) : t('Select role')}
                            </span>
                          </div>
                        </SelectTrigger>
                        <SelectContent className="min-w-[240px]">
                          {ROLE_OPTIONS.map((role) => {
                            const Icon = role.icon
                            return (
                              <SelectItem key={role.value} value={role.value}>
                                <div className="flex items-start gap-2 w-full">
                                  <Icon className="h-4 w-4 shrink-0 mt-0.5" />
                                  <div className="flex flex-col min-w-0 flex-1">
                                    <span className="text-[13px] font-medium">
                                      {t(role.label)}
                                    </span>
                                    <span className="text-[11px] text-muted-foreground leading-tight mt-0.5">
                                      {t(role.description)}
                                    </span>
                                  </div>
                                </div>
                              </SelectItem>
                            )
                          })}
                        </SelectContent>
                      </Select>
                    </div>
                  )}

                  {/* Remove Button */}
                  {invites.length > 1 && (
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-9 w-9 p-0 shrink-0"
                      onClick={() => handleRemoveInvite(index)}
                    >
                      <X className="h-4 w-4" />
                    </Button>
                  )}
                </div>
              )
            })}
          </div>

          {/* Add More Button */}
          {canAddMore && (
            <Button
              variant="outline"
              size="sm"
              className="mt-3 h-9 text-[13px]"
              onClick={handleAddInvite}
            >
              <Plus className="me-1.5 h-4 w-4" />
              {t('Add another member')}
            </Button>
          )}
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => handleOpenChange(false)}
            disabled={createMembershipMutation.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            variant="brandCta"
            size="sm"
            className="h-9 text-[13px] font-medium"
            onClick={handleInvite}
            disabled={!isValid || createMembershipMutation.isPending}
          >
            {`${t('Invite')} ${invites.filter((i) => i.email.trim() !== '').length} ${invites.filter((i) => i.email.trim() !== '').length !== 1 ? t('members') : t('member')}`}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
