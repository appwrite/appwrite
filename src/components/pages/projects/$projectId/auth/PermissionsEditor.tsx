'use client'

import * as React from 'react'
import {
  useState,
  useEffect,
  useCallback,
  useRef,
  forwardRef,
  useImperativeHandle,
  useMemo,
} from 'react'
import {
  Plus,
  X,
  Users,
  User,
  Building2,
  Tag,
  Code,
  ArrowLeft,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
  DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import {
  useProjectUsers,
  useProjectTeams,
  useUserMemberships,
} from '@/lib/react-query/hooks'
import { useParams } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { useT } from '@/lib/i18n/translate'

export type PermissionsEditorHandle = {
  getPermissions: () => string[]
}

export interface PermissionsEditorProps {
  permissions: string[]
  onPermissionsChange?: (permissions: string[]) => void
  /** When true, skip live export; read final values via ref.getPermissions() instead. */
  deferChanges?: boolean
  withCreate?: boolean
  /** When true, show write instead of create (e.g. storage files). */
  withWrite?: boolean
  /** When true, only parse/display "execute" permission (e.g. for functions) */
  executeOnly?: boolean
  projectId?: string
  /** Tighter table padding and column mins (e.g. storage file inspector pane). */
  compact?: boolean
}

interface PermissionActions {
  create: boolean
  read: boolean
  update: boolean
  delete: boolean
  write: boolean
  execute: boolean
}

type PermissionAction =
  | 'create'
  | 'read'
  | 'update'
  | 'delete'
  | 'write'
  | 'execute'

const EMPTY_ACTIONS: PermissionActions = {
  create: false,
  read: false,
  update: false,
  delete: false,
  write: false,
  execute: false,
}

type PermissionMode = {
  executeOnly?: boolean
  withWrite?: boolean
}

function hasAnyPermission(
  actions: PermissionActions,
  mode: PermissionMode,
): boolean {
  if (mode.executeOnly) return actions.execute
  if (mode.withWrite) {
    return actions.read || actions.update || actions.delete || actions.write
  }
  return actions.create || actions.read || actions.update || actions.delete
}

/**
 * Parse permission strings into a map of role -> actions
 */
function parsePermissions(
  perms: string[],
  mode: PermissionMode,
): Map<string, PermissionActions> {
  const roleMap = new Map<string, PermissionActions>()

  if (!Array.isArray(perms)) {
    return roleMap
  }

  perms.forEach((perm) => {
    if (typeof perm !== 'string') {
      return
    }

    // Match pattern: action("role") or action('role')
    const match = perm.match(/(\w+)\(["']([^"']+)["']\)/)
    if (match) {
      const [, action, role] = match
      if (!roleMap.has(role)) {
        roleMap.set(role, { ...EMPTY_ACTIONS })
      }
      const actions = roleMap.get(role)!
      if (mode.executeOnly) {
        if (action === 'execute') actions.execute = true
      } else if (mode.withWrite) {
        if (
          action === 'read' ||
          action === 'update' ||
          action === 'delete' ||
          action === 'write'
        ) {
          actions[action] = true
        }
      } else if (
        action === 'create' ||
        action === 'read' ||
        action === 'update' ||
        action === 'delete'
      ) {
        actions[action] = true
      }
    } else if (mode.executeOnly && perm.trim()) {
      // Function execute array can be plain role names (e.g. ["any"], ["users"])
      const role = perm.trim()
      if (!roleMap.has(role)) {
        roleMap.set(role, { ...EMPTY_ACTIONS })
      }
      roleMap.get(role)!.execute = true
    }
  })

  return roleMap
}

/**
 * Convert role map back to permission strings array
 */
function exportPermissions(
  roleMap: Map<string, PermissionActions>,
  mode: PermissionMode & { withCreate?: boolean },
): string[] {
  const perms: string[] = []

  if (mode.executeOnly) {
    // Function execute attribute expects plain role names (e.g. ["any"], ["users"])
    roleMap.forEach((actions, role) => {
      if (actions.execute) {
        perms.push(role)
      }
    })
    return perms
  }

  roleMap.forEach((actions, role) => {
    const exportActions = mode.withWrite
      ? (['read', 'update', 'delete', 'write'] as const)
      : ([
          ...(mode.withCreate ? (['create'] as const) : []),
          'read',
          'update',
          'delete',
        ] as const)

    exportActions.forEach((action) => {
      if (actions[action]) {
        perms.push(`${action}("${role}")`)
      }
    })
  })

  return perms
}

/**
 * Check if two permission arrays are equal (order-independent)
 */
function permissionsEqual(a: string[], b: string[]): boolean {
  if (a.length !== b.length) return false
  const sortedA = [...a].sort()
  const sortedB = [...b].sort()
  return sortedA.every((val, idx) => val === sortedB[idx])
}

/**
 * RoleDisplay component - displays different role types with appropriate styling
 */
interface RoleDisplayProps {
  role: string
  projectId?: string
}

function RoleDisplay({ role, projectId }: RoleDisplayProps) {
  const t = useT()
  // Special roles
  if (role === 'any') {
    return (
      <span className="text-[13px] font-medium text-foreground">
        {t('Any')}
      </span>
    )
  }

  if (role === 'guests') {
    return (
      <span className="text-[13px] font-medium text-foreground">
        {t('All guests')}
      </span>
    )
  }

  if (role === 'users') {
    return (
      <span className="text-[13px] font-medium text-foreground">
        {t('All users')}
      </span>
    )
  }

  // User role: user:userId or user:userId/roleName
  const userMatch = role.match(/^user:([^/]+)(?:\/(.+))?$/)
  if (userMatch) {
    const [, userId, roleName] = userMatch
    return (
      <UserRoleDisplay
        userId={userId}
        roleName={roleName}
        projectId={projectId}
      />
    )
  }

  // Team role: team:teamId or team:teamId/roleName
  const teamMatch = role.match(/^team:([^/]+)(?:\/(.+))?$/)
  if (teamMatch) {
    const [, teamId, roleName] = teamMatch
    return (
      <TeamRoleDisplay
        teamId={teamId}
        roleName={roleName}
        projectId={projectId}
      />
    )
  }

  // Member role: member:membershipId
  const memberMatch = role.match(/^member:(.+)$/)
  if (memberMatch) {
    const [, membershipId] = memberMatch
    return <MemberRoleDisplay membershipId={membershipId} />
  }

  // Label role: label:labelName
  const labelMatch = role.match(/^label:(.+)$/)
  if (labelMatch) {
    const [, labelName] = labelMatch
    return <LabelRoleDisplay labelName={labelName} />
  }

  // Custom role (fallback)
  return <CustomRoleDisplay role={role} />
}

/**
 * UserRoleDisplay - displays user role with avatar and info
 */
interface UserRoleDisplayProps {
  userId: string
  roleName?: string
  projectId?: string
}

function UserRoleDisplay({ userId, projectId }: UserRoleDisplayProps) {
  const t = useT()
  const { users } = useProjectUsers(projectId || null, 0, 100, '')
  const user = users.find((u) => u.$id === userId)

  const displayName = user?.name || user?.email || user?.phone || userId
  const initials = user?.name
    ? user.name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2)
    : user?.email
      ? user.email[0].toUpperCase()
      : '?'

  return (
    <div className="flex items-center gap-2 min-w-0">
      <Avatar className="size-6 shrink-0">
        {user?.avatar && <AvatarImage src={user.avatar} alt={displayName} />}
        <AvatarFallback className="bg-muted text-muted-foreground text-[10px]">
          {initials}
        </AvatarFallback>
      </Avatar>
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-1.5">
          <span className="text-[13px] font-medium text-foreground truncate">
            {displayName}
          </span>
          <Badge variant="info" className="text-[10px] shrink-0">
            {t('User')}
          </Badge>
        </div>
        {userId && (
          <p className="text-[11px] text-muted-foreground truncate">{userId}</p>
        )}
      </div>
    </div>
  )
}

/**
 * TeamRoleDisplay - displays team role with icon and info
 */
interface TeamRoleDisplayProps {
  teamId: string
  roleName?: string
  projectId?: string
}

function TeamRoleDisplay({ teamId, projectId }: TeamRoleDisplayProps) {
  const t = useT()
  const { teams } = useProjectTeams(projectId || null, 0, 100, '')
  const team = teams.find((item) => item.id === teamId)

  const displayName = team?.name || teamId

  return (
    <div className="flex items-center gap-2 min-w-0">
      <div className="size-6 shrink-0 rounded-full bg-muted flex items-center justify-center">
        <Building2 className="size-3.5 text-muted-foreground" />
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-1.5">
          <span className="text-[13px] font-medium text-foreground truncate">
            {displayName}
          </span>
          <Badge variant="info" className="text-[10px] shrink-0">
            {t('Team')}
          </Badge>
        </div>
        {teamId && (
          <p className="text-[11px] text-muted-foreground truncate">{teamId}</p>
        )}
      </div>
    </div>
  )
}

/**
 * LabelRoleDisplay - displays label role
 */
interface LabelRoleDisplayProps {
  labelName: string
}

function LabelRoleDisplay({ labelName }: LabelRoleDisplayProps) {
  const t = useT()
  return (
    <div className="flex items-center gap-2 min-w-0">
      <div className="size-6 shrink-0 rounded-full bg-muted flex items-center justify-center">
        <Tag className="size-3.5 text-muted-foreground" />
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-1.5">
          <span className="text-[13px] font-medium text-foreground truncate max-w-[120px] sm:max-w-[200px]">
            {labelName}
          </span>
          <Badge variant="info" className="text-[10px] shrink-0">
            {t('Label')}
          </Badge>
        </div>
      </div>
    </div>
  )
}

/**
 * MemberRoleDisplay - displays membership role
 *
 * The role string carries only the membership ID, and every membership lookup
 * needs a teamId or a userId, so the ID is shown as-is rather than resolved.
 */
interface MemberRoleDisplayProps {
  membershipId: string
}

function MemberRoleDisplay({ membershipId }: MemberRoleDisplayProps) {
  const t = useT()
  return (
    <div className="flex items-center gap-2 min-w-0">
      <div className="size-6 shrink-0 rounded-full bg-muted flex items-center justify-center">
        <Users className="size-3.5 text-muted-foreground" />
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-1.5">
          <span className="text-[13px] font-medium text-foreground truncate max-w-[120px] sm:max-w-[200px]">
            {membershipId}
          </span>
          <Badge variant="info" className="text-[10px] shrink-0">
            {t('Member')}
          </Badge>
        </div>
      </div>
    </div>
  )
}

/**
 * CustomRoleDisplay - displays custom role
 */
interface CustomRoleDisplayProps {
  role: string
}

function CustomRoleDisplay({ role }: CustomRoleDisplayProps) {
  const t = useT()
  return (
    <div className="flex items-center gap-2 min-w-0">
      <div className="size-6 shrink-0 rounded-full bg-muted flex items-center justify-center">
        <Code className="size-3.5 text-muted-foreground" />
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-1.5">
          <span className="text-[13px] font-medium text-foreground truncate max-w-[120px] sm:max-w-[200px]">
            {role}
          </span>
          <Badge variant="info" className="text-[10px] shrink-0">
            {t('Custom')}
          </Badge>
        </div>
      </div>
    </div>
  )
}

/**
 * UserSelectionModal - modal for selecting users
 */
interface UserSelectionModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSelect: (userIds: string[]) => void
  projectId?: string
  existingRoles?: Set<string>
}

function UserSelectionModal({
  open,
  onOpenChange,
  onSelect,
  projectId,
  existingRoles,
}: UserSelectionModalProps) {
  const t = useT()
  const [search, setSearch] = useState('')
  const [selectedUserIds, setSelectedUserIds] = useState<Set<string>>(new Set())
  const [page, setPage] = useState(0)
  const pageSize = 25

  const { users, isLoading } = useProjectUsers(
    projectId || null,
    page,
    pageSize,
    search,
  )

  // Check if a user is already in permissions
  const isUserAlreadyAdded = (userId: string) => {
    return existingRoles?.has(`user:${userId}`) || false
  }

  const handleToggleUser = (userId: string) => {
    const newSelected = new Set(selectedUserIds)
    if (newSelected.has(userId)) {
      newSelected.delete(userId)
    } else {
      newSelected.add(userId)
    }
    setSelectedUserIds(newSelected)
  }

  const handleAdd = () => {
    const userIds = Array.from(selectedUserIds)
    if (userIds.length > 0) {
      onSelect(userIds)
      setSelectedUserIds(new Set())
      setSearch('')
      onOpenChange(false)
    }
  }

  const handleCancel = () => {
    setSelectedUserIds(new Set())
    setSearch('')
    onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Select Users')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Choose one or more users to add permissions for.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0">
          <div className="space-y-4">
            <Input
              placeholder={t('Search users by name, email, or ID...')}
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(0)
              }}
            />

            <div className="max-h-[300px] overflow-y-auto space-y-1">
              {isLoading ? (
                <div className="text-center py-8 text-sm text-muted-foreground">
                  {t('Loading users...')}
                </div>
              ) : users.length === 0 ? (
                <EmptyState
                  icon={User}
                  isEmpty={!search}
                  hasFilters={!!search}
                  className="py-8"
                />
              ) : (
                users.map((user) => {
                  const isSelected = selectedUserIds.has(user.$id)
                  const isAlreadyAdded = isUserAlreadyAdded(user.$id)
                  const displayName =
                    user.name || user.email || user.phone || user.$id
                  const initials = user.name
                    ? user.name
                        .split(' ')
                        .map((n) => n[0])
                        .join('')
                        .toUpperCase()
                        .slice(0, 2)
                    : user.email
                      ? user.email[0].toUpperCase()
                      : '?'

                  return (
                    <div
                      key={user.$id}
                      onClick={() =>
                        !isAlreadyAdded && handleToggleUser(user.$id)
                      }
                      className={cn(
                        'flex items-center gap-3 rounded-lg border p-3 transition-colors',
                        isAlreadyAdded
                          ? 'border-border bg-muted/30 opacity-50 cursor-not-allowed'
                          : isSelected
                            ? 'border-primary bg-primary/5 cursor-pointer'
                            : 'border-border hover:bg-muted/50 cursor-pointer',
                      )}
                    >
                      <Checkbox
                        checked={isSelected}
                        onCheckedChange={() => {
                          if (!isAlreadyAdded) handleToggleUser(user.$id)
                        }}
                        disabled={isAlreadyAdded}
                        onClick={(e) => e.stopPropagation()}
                        className="cursor-pointer"
                      />
                      <Avatar className="size-8">
                        {user.avatar && (
                          <AvatarImage src={user.avatar} alt={displayName} />
                        )}
                        <AvatarFallback className="bg-muted text-muted-foreground text-xs">
                          {initials}
                        </AvatarFallback>
                      </Avatar>
                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-medium truncate">
                          {displayName}
                        </p>
                        {user.email && (
                          <p className="text-xs text-muted-foreground truncate">
                            {user.email}
                          </p>
                        )}
                      </div>
                    </div>
                  )
                })
              )}
            </div>
          </div>
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={handleCancel}>
            {t('Cancel')}
          </Button>
          <Button onClick={handleAdd} disabled={selectedUserIds.size === 0}>
            {t('Add')}{' '}
            {selectedUserIds.size > 0 ? `(${selectedUserIds.size})` : ''}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

/**
 * TeamSelectionModal - modal for selecting teams
 */
interface TeamSelectionModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSelect: (teamIds: string[]) => void
  projectId?: string
  existingRoles?: Set<string>
}

function TeamSelectionModal({
  open,
  onOpenChange,
  onSelect,
  projectId,
  existingRoles,
}: TeamSelectionModalProps) {
  const t = useT()
  const [search, setSearch] = useState('')
  const [selectedTeamIds, setSelectedTeamIds] = useState<Set<string>>(new Set())
  const [page, setPage] = useState(0)
  const pageSize = 25

  const { teams, isLoading } = useProjectTeams(
    projectId || null,
    page,
    pageSize,
    search,
  )

  // Check if a team is already in permissions
  const isTeamAlreadyAdded = (teamId: string) => {
    return existingRoles?.has(`team:${teamId}`) || false
  }

  const handleToggleTeam = (teamId: string) => {
    const newSelected = new Set(selectedTeamIds)
    if (newSelected.has(teamId)) {
      newSelected.delete(teamId)
    } else {
      newSelected.add(teamId)
    }
    setSelectedTeamIds(newSelected)
  }

  const handleAdd = () => {
    const teamIds = Array.from(selectedTeamIds)
    if (teamIds.length > 0) {
      onSelect(teamIds)
      setSelectedTeamIds(new Set())
      setSearch('')
      onOpenChange(false)
    }
  }

  const handleCancel = () => {
    setSelectedTeamIds(new Set())
    setSearch('')
    onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Select Teams')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Choose one or more teams to add permissions for.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0">
          <div className="space-y-4">
            <Input
              placeholder={t('Search teams by name or ID...')}
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(0)
              }}
            />

            <div className="max-h-[300px] overflow-y-auto space-y-1">
              {isLoading ? (
                <div className="text-center py-8 text-sm text-muted-foreground">
                  {t('Loading teams...')}
                </div>
              ) : teams.length === 0 ? (
                <EmptyState
                  icon={Users}
                  isEmpty={!search}
                  hasFilters={!!search}
                  className="py-8"
                />
              ) : (
                teams.map((team) => {
                  const isSelected = selectedTeamIds.has(team.id)
                  const isAlreadyAdded = isTeamAlreadyAdded(team.id)

                  return (
                    <div
                      key={team.id}
                      onClick={() =>
                        !isAlreadyAdded && handleToggleTeam(team.id)
                      }
                      className={cn(
                        'flex items-center gap-3 rounded-lg border p-3 transition-colors',
                        isAlreadyAdded
                          ? 'border-border bg-muted/30 opacity-50 cursor-not-allowed'
                          : isSelected
                            ? 'border-primary bg-primary/5 cursor-pointer'
                            : 'border-border hover:bg-muted/50 cursor-pointer',
                      )}
                    >
                      <Checkbox
                        checked={isSelected}
                        onCheckedChange={() => {
                          if (!isAlreadyAdded) handleToggleTeam(team.id)
                        }}
                        disabled={isAlreadyAdded}
                        onClick={(e) => e.stopPropagation()}
                        className="cursor-pointer"
                      />
                      <div className="size-8 rounded-full bg-muted flex items-center justify-center shrink-0">
                        <Building2 className="size-4 text-muted-foreground" />
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-medium truncate">
                          {team.name}
                        </p>
                        <p className="text-xs text-muted-foreground truncate">
                          {team.id}
                        </p>
                      </div>
                    </div>
                  )
                })
              )}
            </div>
          </div>
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={handleCancel}>
            {t('Cancel')}
          </Button>
          <Button onClick={handleAdd} disabled={selectedTeamIds.size === 0}>
            {t('Add')}{' '}
            {selectedTeamIds.size > 0 ? `(${selectedTeamIds.size})` : ''}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

/**
 * MemberSelectionModal - modal for selecting team memberships
 *
 * Two steps: pick the user first, then pick which of their team memberships to
 * grant. `teams.listMemberships` only indexes membership and user IDs for
 * search, so a team-first flow would have a search box that matches nothing.
 */
interface MemberSelectionModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSelect: (roles: string[]) => void
  projectId?: string
  existingRoles?: Set<string>
}

interface MemberSelectionUser {
  $id: string
  name?: string
  email?: string
  phone?: string
  avatar?: string
}

function MemberSelectionModal({
  open,
  onOpenChange,
  onSelect,
  projectId,
  existingRoles,
}: MemberSelectionModalProps) {
  const t = useT()
  const [search, setSearch] = useState('')
  const [selectedUser, setSelectedUser] = useState<MemberSelectionUser | null>(
    null,
  )
  const [selectedMembershipIds, setSelectedMembershipIds] = useState<
    Set<string>
  >(new Set())
  const [page, setPage] = useState(0)
  const pageSize = 25

  const { users, isLoading } = useProjectUsers(
    projectId || null,
    page,
    pageSize,
    search,
  )

  const { data: membershipsData, isLoading: isLoadingMemberships } =
    useUserMemberships(projectId || null, selectedUser?.$id || null)
  const memberships = membershipsData?.memberships || []

  // Check if a membership is already in permissions
  const isMembershipAlreadyAdded = (membershipId: string) => {
    return existingRoles?.has(`member:${membershipId}`) || false
  }

  const handleToggleMembership = (membershipId: string) => {
    const newSelected = new Set(selectedMembershipIds)
    if (newSelected.has(membershipId)) {
      newSelected.delete(membershipId)
    } else {
      newSelected.add(membershipId)
    }
    setSelectedMembershipIds(newSelected)
  }

  const resetState = () => {
    setSelectedMembershipIds(new Set())
    setSelectedUser(null)
    setSearch('')
    setPage(0)
  }

  const handleAdd = () => {
    const membershipIds = Array.from(selectedMembershipIds)
    if (membershipIds.length > 0) {
      onSelect(membershipIds.map((membershipId) => `member:${membershipId}`))
      resetState()
      onOpenChange(false)
    }
  }

  const handleCancel = () => {
    resetState()
    onOpenChange(false)
  }

  const handleBack = () => {
    setSelectedUser(null)
    setSelectedMembershipIds(new Set())
  }

  const selectedUserName =
    selectedUser?.name ||
    selectedUser?.email ||
    selectedUser?.phone ||
    selectedUser?.$id ||
    ''
  const selectedUserInitials = selectedUser?.name
    ? selectedUser.name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2)
    : selectedUser?.email
      ? selectedUser.email[0].toUpperCase()
      : '?'

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        // Escape, the overlay and the close button all route through here, so the step-two
        // state has to be cleared here too or the next open resumes on the previous user.
        if (!next) resetState()
        onOpenChange(next)
      }}
    >
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Select Memberships')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {selectedUser
              ? t('Choose one or more team memberships to add permissions for.')
              : t('Choose a user to see the team memberships you can add.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0">
          <div className="space-y-4">
            {selectedUser ? (
              <>
                <div className="flex items-center gap-3 rounded-lg border border-border bg-muted/30 p-3">
                  <Button
                    variant="ghost"
                    size="icon"
                    className="size-8 shrink-0"
                    onClick={handleBack}
                    aria-label={t('Back to users')}
                  >
                    <ArrowLeft className="size-4 rtl:-scale-x-100" />
                  </Button>
                  <Avatar className="size-8">
                    {selectedUser.avatar && (
                      <AvatarImage
                        src={selectedUser.avatar}
                        alt={selectedUserName}
                      />
                    )}
                    <AvatarFallback className="bg-muted text-muted-foreground text-xs">
                      {selectedUserInitials}
                    </AvatarFallback>
                  </Avatar>
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium truncate">
                      {selectedUserName}
                    </p>
                    <p className="text-xs text-muted-foreground truncate">
                      {selectedUser.$id}
                    </p>
                  </div>
                </div>

                <div className="max-h-[300px] overflow-y-auto space-y-1">
                  {isLoadingMemberships ? (
                    <div className="text-center py-8 text-sm text-muted-foreground">
                      {t('Loading memberships...')}
                    </div>
                  ) : memberships.length === 0 ? (
                    <EmptyState
                      icon={Users}
                      title={t('No memberships available')}
                      description={t('This user is not a member of any teams.')}
                      isEmpty
                      className="py-8"
                    />
                  ) : (
                    memberships.map((membership) => {
                      const isSelected = selectedMembershipIds.has(
                        membership.$id,
                      )
                      const isAlreadyAdded = isMembershipAlreadyAdded(
                        membership.$id,
                      )

                      return (
                        <div
                          key={membership.$id}
                          onClick={() =>
                            !isAlreadyAdded &&
                            handleToggleMembership(membership.$id)
                          }
                          className={cn(
                            'flex items-center gap-3 rounded-lg border p-3 transition-colors',
                            isAlreadyAdded
                              ? 'border-border bg-muted/30 opacity-50 cursor-not-allowed'
                              : isSelected
                                ? 'border-primary bg-primary/5 cursor-pointer'
                                : 'border-border hover:bg-muted/50 cursor-pointer',
                          )}
                        >
                          <Checkbox
                            checked={isSelected}
                            onCheckedChange={() => {
                              if (!isAlreadyAdded)
                                handleToggleMembership(membership.$id)
                            }}
                            disabled={isAlreadyAdded}
                            onClick={(e) => e.stopPropagation()}
                            className="cursor-pointer"
                          />
                          <div className="size-8 rounded-full bg-muted flex items-center justify-center shrink-0">
                            <Users className="size-4 text-muted-foreground" />
                          </div>
                          <div className="flex-1 min-w-0">
                            <p className="text-sm font-medium truncate">
                              {membership.teamName || membership.teamId}
                            </p>
                            <p className="text-xs text-muted-foreground truncate">
                              {membership.$id}
                            </p>
                          </div>
                        </div>
                      )
                    })
                  )}
                </div>
              </>
            ) : (
              <>
                <Input
                  placeholder={t('Search users by name, email, or ID...')}
                  value={search}
                  onChange={(e) => {
                    setSearch(e.target.value)
                    setPage(0)
                  }}
                />

                <div className="max-h-[300px] overflow-y-auto space-y-1">
                  {isLoading ? (
                    <div className="text-center py-8 text-sm text-muted-foreground">
                      {t('Loading users...')}
                    </div>
                  ) : users.length === 0 ? (
                    <EmptyState
                      icon={User}
                      isEmpty={!search}
                      hasFilters={!!search}
                      className="py-8"
                    />
                  ) : (
                    users.map((user) => {
                      const displayName =
                        user.name || user.email || user.phone || user.$id
                      const initials = user.name
                        ? user.name
                            .split(' ')
                            .map((n) => n[0])
                            .join('')
                            .toUpperCase()
                            .slice(0, 2)
                        : user.email
                          ? user.email[0].toUpperCase()
                          : '?'

                      return (
                        <button
                          key={user.$id}
                          type="button"
                          onClick={() => setSelectedUser(user)}
                          className="w-full text-start flex items-center gap-3 rounded-lg border border-border p-3 transition-colors hover:bg-muted/50 cursor-pointer"
                        >
                          <Avatar className="size-8">
                            {user.avatar && (
                              <AvatarImage
                                src={user.avatar}
                                alt={displayName}
                              />
                            )}
                            <AvatarFallback className="bg-muted text-muted-foreground text-xs">
                              {initials}
                            </AvatarFallback>
                          </Avatar>
                          <div className="flex-1 min-w-0">
                            <p className="text-sm font-medium truncate">
                              {displayName}
                            </p>
                            {user.email && (
                              <p className="text-xs text-muted-foreground truncate">
                                {user.email}
                              </p>
                            )}
                          </div>
                        </button>
                      )
                    })
                  )}
                </div>
              </>
            )}
          </div>
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={handleCancel}>
            {t('Cancel')}
          </Button>
          <Button
            onClick={handleAdd}
            disabled={!selectedUser || selectedMembershipIds.size === 0}
          >
            {t('Add')}{' '}
            {selectedMembershipIds.size > 0
              ? `(${selectedMembershipIds.size})`
              : ''}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

/**
 * LabelInputModal - modal for entering label name
 */
interface LabelInputModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onAdd: (labelName: string) => void
}

function LabelInputModal({ open, onOpenChange, onAdd }: LabelInputModalProps) {
  const t = useT()
  const [labelName, setLabelName] = useState('')

  const handleAdd = () => {
    if (labelName.trim()) {
      onAdd(labelName.trim())
      setLabelName('')
      onOpenChange(false)
    }
  }

  const handleCancel = () => {
    setLabelName('')
    onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Add Label')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Enter a label name to create a label-based permission.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0">
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="label-name">{t('Label Name')}</Label>
              <Input
                id="label-name"
                placeholder={t('e.g., premium, admin, moderator')}
                value={labelName}
                onChange={(e) => setLabelName(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' && labelName.trim()) {
                    handleAdd()
                  }
                }}
              />
            </div>
          </div>
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={handleCancel}>
            {t('Cancel')}
          </Button>
          <Button onClick={handleAdd} disabled={!labelName.trim()}>
            {t('Add')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

/** Valid permission format: user:ID, user:ID/role, team:ID, team:ID/role, or member:ID */
function isValidPermissionFormat(value: string): boolean {
  const trimmed = value.trim()
  if (!trimmed) return false
  // user:userId or user:userId/roleName
  if (/^user:[^/]+(\/.+)?$/.test(trimmed)) return true
  // team:teamId or team:teamId/roleName
  if (/^team:[^/]+(\/.+)?$/.test(trimmed)) return true
  // member:membershipId - a membership has no sub-roles
  if (/^member:[^/]+$/.test(trimmed)) return true
  return false
}

/**
 * CustomRoleInputModal - modal for entering a role by permission string (user:, team: or member: format)
 */
interface CustomRoleInputModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onAdd: (role: string) => void
}

function CustomRoleInputModal({
  open,
  onOpenChange,
  onAdd,
}: CustomRoleInputModalProps) {
  const t = useT()
  const [role, setRole] = useState('')
  const trimmed = role.trim()
  const isValid = isValidPermissionFormat(trimmed)
  const showFormatError = trimmed.length > 0 && !isValid

  const handleAdd = () => {
    if (isValid) {
      onAdd(trimmed)
      setRole('')
      onOpenChange(false)
    }
  }

  const handleCancel = () => {
    setRole('')
    onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Add by role string')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Grant access using a user, team, or membership ID. Use')}{' '}
            <code className="rounded bg-muted px-1 py-0.5 text-[12px]">
              user:[USER_ID]
            </code>
            ,{' '}
            <code className="rounded bg-muted px-1 py-0.5 text-[12px]">
              team:[TEAM_ID]/[ROLE]
            </code>{' '}
            {t('or')}{' '}
            <code className="rounded bg-muted px-1 py-0.5 text-[12px]">
              member:[MEMBERSHIP_ID]
            </code>
            .
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0">
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="custom-role">{t('Permission string')}</Label>
              <Input
                id="custom-role"
                placeholder="user:USER_ID, team:TEAM_ID/ROLE or member:MEMBERSHIP_ID"
                value={role}
                onChange={(e) => setRole(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' && isValid) {
                    handleAdd()
                  }
                }}
                className={showFormatError ? 'border-destructive' : ''}
              />
              {showFormatError && (
                <p className="text-[12px] text-destructive flex flex-wrap items-center gap-1.5">
                  <span>{t('Use format')}</span>
                  <code className="rounded bg-destructive/10 px-1 py-0.5">
                    user:USER_ID
                  </code>
                  <span>,</span>
                  <code className="rounded bg-destructive/10 px-1 py-0.5">
                    team:TEAM_ID/ROLE
                  </code>
                  <span>{t('or')}</span>
                  <code className="rounded bg-destructive/10 px-1 py-0.5">
                    member:MEMBERSHIP_ID
                  </code>
                </p>
              )}
            </div>
          </div>
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={handleCancel}>
            {t('Cancel')}
          </Button>
          <Button onClick={handleAdd} disabled={!isValid}>
            {t('Add')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

/**
 * Main PermissionsEditor component
 */
export const PermissionsEditor = forwardRef<
  PermissionsEditorHandle,
  PermissionsEditorProps
>(function PermissionsEditor(
  {
    permissions,
    onPermissionsChange,
    deferChanges = false,
    withCreate = false,
    withWrite = false,
    executeOnly = false,
    projectId: projectIdProp,
    compact = false,
  },
  ref,
) {
  const t = useT()
  const params = useParams({ strict: false })
  const projectId = projectIdProp || (params.projectId as string | undefined)
  const permissionMode = useMemo(
    () => ({ executeOnly, withWrite }),
    [executeOnly, withWrite],
  )
  const exportMode = useMemo(
    () => ({ executeOnly, withWrite, withCreate }),
    [executeOnly, withWrite, withCreate],
  )

  const d = compact
    ? {
        pad: 'px-2 py-1.5',
        head: 'text-[11px] font-semibold text-muted-foreground uppercase tracking-wider',
        roleMin: 'min-w-[120px]',
        actMin: 'min-w-[48px]',
        rmCol: 'w-[36px]',
        rmBtn: 'size-7',
        rmIcon: 'size-3.5',
        stack: 'space-y-2',
        emptyY: 'py-6',
        emptyGap: 'gap-2',
      }
    : {
        pad: 'px-4 py-3',
        head: 'text-[12px] font-semibold text-muted-foreground uppercase tracking-wider',
        roleMin: 'min-w-[220px]',
        actMin: 'min-w-[64px]',
        rmCol: 'w-[40px]',
        rmBtn: 'size-8',
        rmIcon: 'size-4',
        stack: 'space-y-4',
        emptyY: 'py-12',
        emptyGap: 'gap-4',
      }

  // Internal state
  const [permissionsMap, setPermissionsMap] = useState<
    Map<string, PermissionActions>
  >(new Map())
  // Track roles that have had at least one permission enabled (to prevent auto-removal of newly added roles)
  const [, setRolesWithPermissions] = useState<Set<string>>(new Set())
  // Track newly added roles that haven't had any permissions set yet (prevent removal until user interacts)
  // Use ref to avoid stale closure issues in state updaters
  const newlyAddedRolesRef = useRef<Set<string>>(new Set())

  // Modal states
  const [userModalOpen, setUserModalOpen] = useState(false)
  const [teamModalOpen, setTeamModalOpen] = useState(false)
  const [memberModalOpen, setMemberModalOpen] = useState(false)
  const [labelModalOpen, setLabelModalOpen] = useState(false)
  const [customModalOpen, setCustomModalOpen] = useState(false)

  // Track if this is the initial mount
  const isInitialMountRef = useRef(true)
  // Track the last exported permissions to detect if props changed from our export
  const lastExportedRef = useRef<string>('')

  // Initialize permissions map from props (only on mount or when props change externally)
  useEffect(() => {
    // Create a stable string representation of permissions for comparison
    const permissionsStr = JSON.stringify([...permissions].sort())

    // On initial mount, always initialize
    if (isInitialMountRef.current) {
      isInitialMountRef.current = false
      // Only set lastExportedRef if permissions are not empty (to allow initialization of real data later)
      if (permissions.length > 0) {
        lastExportedRef.current = permissionsStr
      }
      const newMap = parsePermissions(permissions, permissionMode)
      setPermissionsMap(newMap)
      // Initialize rolesWithPermissions with roles that already have permissions
      const rolesWithPerms = new Set<string>()
      newMap.forEach((actions, role) => {
        if (hasAnyPermission(actions, permissionMode)) {
          rolesWithPerms.add(role)
        }
      })
      setRolesWithPermissions(rolesWithPerms)
      return
    }

    // After initial mount, check if this is from our own export
    if (lastExportedRef.current && lastExportedRef.current === permissionsStr) {
      // This is from our own export, don't re-initialize
      return
    }

    // If we had empty permissions initially and now have real permissions, initialize
    if (!lastExportedRef.current && permissions.length > 0) {
      lastExportedRef.current = permissionsStr
      const newMap = parsePermissions(permissions, permissionMode)
      setPermissionsMap(newMap)
      // Initialize rolesWithPermissions
      const rolesWithPerms = new Set<string>()
      newMap.forEach((actions, role) => {
        if (hasAnyPermission(actions, permissionMode)) {
          rolesWithPerms.add(role)
        }
      })
      setRolesWithPermissions(rolesWithPerms)
      return
    }

    // Permissions changed externally - merge with current state to preserve newly added roles
    const newMap = parsePermissions(permissions, permissionMode)

    // If current map is empty and we have new permissions, just set them directly
    setPermissionsMap((prevMap) => {
      // If previous map is empty, just use the new map (first time loading permissions)
      if (prevMap.size === 0) {
        const rolesWithPerms = new Set<string>()
        newMap.forEach((actions, role) => {
          if (hasAnyPermission(actions, permissionMode)) {
            rolesWithPerms.add(role)
          }
        })
        setRolesWithPermissions(rolesWithPerms)
        return newMap
      }

      // Otherwise, merge to preserve newly added roles
      const mergedMap = new Map(newMap)
      prevMap.forEach((actions, role) => {
        if (!mergedMap.has(role)) {
          // Keep roles that exist in current map but not in parsed permissions
          // This includes newly added roles
          mergedMap.set(role, { ...actions })
        } else {
          // Update existing roles with parsed permissions (in case they changed externally)
          const parsedActions = mergedMap.get(role)!
          mergedMap.set(role, { ...parsedActions })
        }
      })

      // Update rolesWithPermissions
      const rolesWithPerms = new Set<string>()
      mergedMap.forEach((actions, role) => {
        if (hasAnyPermission(actions, permissionMode)) {
          rolesWithPerms.add(role)
        }
      })
      setRolesWithPermissions(rolesWithPerms)

      return mergedMap
    })
  }, [permissions, permissionMode])

  useImperativeHandle(
    ref,
    () => ({
      getPermissions: () => exportPermissions(permissionsMap, exportMode),
    }),
    [permissionsMap, exportMode],
  )

  // Export permissions when map changes (but not during initialization)
  useEffect(() => {
    if (deferChanges || !onPermissionsChange) {
      return
    }

    // Skip export during initial mount
    if (isInitialMountRef.current) {
      return
    }

    const exported = exportPermissions(permissionsMap, exportMode)
    const exportedStr = JSON.stringify([...exported].sort())

    // Only export if:
    // 1. Different from current permissions prop
    // 2. Different from what we last exported (prevents loops)
    if (
      !permissionsEqual(exported, permissions) &&
      lastExportedRef.current !== exportedStr
    ) {
      lastExportedRef.current = exportedStr
      onPermissionsChange(exported)
    }
  }, [
    permissionsMap,
    permissions,
    onPermissionsChange,
    exportMode,
    deferChanges,
  ])

  const handlePermissionChange = useCallback(
    (role: string, action: PermissionAction, enabled: boolean) => {
      setPermissionsMap((prev) => {
        const newMap = new Map(prev)
        if (!newMap.has(role)) {
          newMap.set(role, { ...EMPTY_ACTIONS })
        }
        const actions = newMap.get(role)!
        const hadPermissionsBefore = hasAnyPermission(actions, permissionMode)
        actions[action] = enabled

        // Track if this role has had permissions set
        if (enabled) {
          setRolesWithPermissions((prev) => new Set(prev).add(role))
          // Remove from newly added roles once user sets a permission
          newlyAddedRolesRef.current.delete(role)
        }

        // Only remove role if:
        // 1. All permissions are disabled (after the change)
        // 2. It previously had permissions enabled (user had configured it before)
        // 3. It's NOT a newly added role (extra safety check - newly added roles should never be auto-removed)
        const isNewlyAdded = newlyAddedRolesRef.current.has(role)
        const allDisabled = !hasAnyPermission(actions, permissionMode)

        // NEVER remove newly added roles, even if all permissions are disabled
        // Only remove if it had permissions before AND all are now disabled AND it's not newly added
        if (allDisabled && hadPermissionsBefore && !isNewlyAdded) {
          newMap.delete(role)
          setRolesWithPermissions((prev) => {
            const newSet = new Set(prev)
            newSet.delete(role)
            return newSet
          })
        }

        return newMap
      })
    },
    [permissionMode],
  )

  const handleRemoveRole = useCallback((role: string) => {
    setPermissionsMap((prev) => {
      const newMap = new Map(prev)
      newMap.delete(role)
      return newMap
    })
    setRolesWithPermissions((prev) => {
      const newSet = new Set(prev)
      newSet.delete(role)
      return newSet
    })
    newlyAddedRolesRef.current.delete(role)
  }, [])

  const handleAddRole = useCallback(
    (role: string) => {
      if (permissionsMap.has(role)) {
        return // Don't add duplicate
      }
      setPermissionsMap((prev) => {
        const newMap = new Map(prev)
        // In executeOnly, adding a role grants execute immediately (no checkbox)
        newMap.set(role, {
          ...EMPTY_ACTIONS,
          ...(executeOnly ? { execute: true } : {}),
        })
        return newMap
      })
      if (executeOnly) {
        setRolesWithPermissions((prev) => new Set(prev).add(role))
      } else {
        newlyAddedRolesRef.current.add(role)
      }
    },
    [permissionsMap, executeOnly],
  )

  const handleAddUsers = useCallback(
    (userIds: string[]) => {
      userIds.forEach((userId) => {
        handleAddRole(`user:${userId}`)
      })
    },
    [handleAddRole],
  )

  const handleAddTeams = useCallback(
    (teamIds: string[]) => {
      teamIds.forEach((teamId) => {
        handleAddRole(`team:${teamId}`)
      })
    },
    [handleAddRole],
  )

  const handleAddMembers = useCallback(
    (roles: string[]) => {
      roles.forEach((role) => {
        handleAddRole(role)
      })
    },
    [handleAddRole],
  )

  const handleAddLabel = useCallback(
    (labelName: string) => {
      handleAddRole(`label:${labelName}`)
    },
    [handleAddRole],
  )

  const handleAddCustom = useCallback(
    (role: string) => {
      handleAddRole(role)
    },
    [handleAddRole],
  )

  const roles = Array.from(permissionsMap.keys())
  const hasAny = permissionsMap.has('any')
  const hasGuests = permissionsMap.has('guests')
  const hasUsers = permissionsMap.has('users')

  // Empty state
  if (roles.length === 0) {
    return (
      <div
        className={cn(
          'flex flex-col items-center justify-center',
          d.emptyGap,
          d.emptyY,
        )}
      >
        <AddRoleDropdown
          onAddSpecialRole={handleAddRole}
          onOpenUserModal={() => setUserModalOpen(true)}
          onOpenTeamModal={() => setTeamModalOpen(true)}
          onOpenMemberModal={() => setMemberModalOpen(true)}
          onOpenLabelModal={() => setLabelModalOpen(true)}
          onOpenCustomModal={() => setCustomModalOpen(true)}
          hasAny={hasAny}
          hasGuests={hasGuests}
          hasUsers={hasUsers}
          emptyState
        />
        <p
          className={cn(
            'text-muted-foreground',
            compact ? 'text-[12px]' : 'text-sm',
          )}
        >
          {executeOnly
            ? t('Add roles to choose who can execute')
            : t('Add a role to get started')}
        </p>

        {/* Modals */}
        <UserSelectionModal
          open={userModalOpen}
          onOpenChange={setUserModalOpen}
          onSelect={handleAddUsers}
          projectId={projectId}
          existingRoles={new Set(permissionsMap.keys())}
        />
        <TeamSelectionModal
          open={teamModalOpen}
          onOpenChange={setTeamModalOpen}
          onSelect={handleAddTeams}
          projectId={projectId}
          existingRoles={new Set(permissionsMap.keys())}
        />
        <MemberSelectionModal
          open={memberModalOpen}
          onOpenChange={setMemberModalOpen}
          onSelect={handleAddMembers}
          projectId={projectId}
          existingRoles={new Set(permissionsMap.keys())}
        />
        <LabelInputModal
          open={labelModalOpen}
          onOpenChange={setLabelModalOpen}
          onAdd={handleAddLabel}
        />
        <CustomRoleInputModal
          open={customModalOpen}
          onOpenChange={setCustomModalOpen}
          onAdd={handleAddCustom}
        />
      </div>
    )
  }

  // Execute-only: simple list of roles (who can execute) - no table or checkboxes
  if (executeOnly) {
    const rolesWithExecute = roles.filter(
      (role) => permissionsMap.get(role)?.execute,
    )
    return (
      <div className={d.stack}>
        <div className="flex flex-wrap gap-2">
          {rolesWithExecute.map((role) => (
            <div
              key={role}
              className={cn(
                'flex items-center gap-2 rounded-lg border border-border bg-muted/30 pe-1 min-w-0',
                compact ? 'ps-2 py-1.5' : 'ps-3 py-2',
              )}
            >
              <RoleDisplay role={role} projectId={projectId} />
              <Button
                variant="ghost"
                size="icon"
                className={cn(compact ? 'size-7' : 'size-8', 'shrink-0')}
                onClick={() => handleRemoveRole(role)}
                aria-label={`${t('Remove')} ${role} ${t('from execute list')}`}
              >
                <X className={compact ? 'size-3.5' : 'size-4'} />
              </Button>
            </div>
          ))}
        </div>
        <AddRoleDropdown
          onAddSpecialRole={handleAddRole}
          onOpenUserModal={() => setUserModalOpen(true)}
          onOpenTeamModal={() => setTeamModalOpen(true)}
          onOpenMemberModal={() => setMemberModalOpen(true)}
          onOpenLabelModal={() => setLabelModalOpen(true)}
          onOpenCustomModal={() => setCustomModalOpen(true)}
          hasAny={hasAny}
          hasGuests={hasGuests}
          hasUsers={hasUsers}
        />
        <UserSelectionModal
          open={userModalOpen}
          onOpenChange={setUserModalOpen}
          onSelect={handleAddUsers}
          projectId={projectId}
          existingRoles={new Set(permissionsMap.keys())}
        />
        <TeamSelectionModal
          open={teamModalOpen}
          onOpenChange={setTeamModalOpen}
          onSelect={handleAddTeams}
          projectId={projectId}
          existingRoles={new Set(permissionsMap.keys())}
        />
        <MemberSelectionModal
          open={memberModalOpen}
          onOpenChange={setMemberModalOpen}
          onSelect={handleAddMembers}
          projectId={projectId}
          existingRoles={new Set(permissionsMap.keys())}
        />
        <LabelInputModal
          open={labelModalOpen}
          onOpenChange={setLabelModalOpen}
          onAdd={handleAddLabel}
        />
        <CustomRoleInputModal
          open={customModalOpen}
          onOpenChange={setCustomModalOpen}
          onAdd={handleAddCustom}
        />
      </div>
    )
  }

  // Table state (CRUD permissions)
  return (
    <div className={d.stack}>
      <div className="overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead
                className={cn(d.pad, d.head, d.roleMin)}
              >
                {t('Role')}
              </TableHead>
              {withCreate && (
                <TableHead
                  className={cn(d.pad, d.head, 'text-center', d.actMin)}
                >
                  {t('Create')}
                </TableHead>
              )}
              <TableHead
                className={cn(d.pad, d.head, 'text-center', d.actMin)}
              >
                {t('Read')}
              </TableHead>
              <TableHead
                className={cn(d.pad, d.head, 'text-center', d.actMin)}
              >
                {t('Update')}
              </TableHead>
              <TableHead
                className={cn(d.pad, d.head, 'text-center', d.actMin)}
              >
                {t('Delete')}
              </TableHead>
              {withWrite && (
                <TableHead
                  className={cn(d.pad, d.head, 'text-center', d.actMin)}
                >
                  {t('Write')}
                </TableHead>
              )}
              <TableHead
                className={cn(d.pad, d.head, d.rmCol)}
              ></TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {roles.map((role) => {
              const actions = permissionsMap.get(role)!
              return (
                <TableRow key={role}>
                  <TableCell className={cn(d.pad, d.roleMin)}>
                    <RoleDisplay role={role} projectId={projectId} />
                  </TableCell>
                  {withCreate && (
                    <TableCell className={cn(d.pad, d.actMin, 'text-center')}>
                      <Checkbox
                        checked={actions.create}
                        onCheckedChange={(checked) =>
                          handlePermissionChange(
                            role,
                            'create',
                            checked === true,
                          )
                        }
                        aria-label={`${t('Create permission for')} ${role}`}
                      />
                    </TableCell>
                  )}
                  <TableCell className={cn(d.pad, d.actMin, 'text-center')}>
                    <Checkbox
                      checked={actions.read}
                      onCheckedChange={(checked) =>
                        handlePermissionChange(role, 'read', checked === true)
                      }
                      aria-label={`${t('Read permission for')} ${role}`}
                    />
                  </TableCell>
                  <TableCell className={cn(d.pad, d.actMin, 'text-center')}>
                    <Checkbox
                      checked={actions.update}
                      onCheckedChange={(checked) =>
                        handlePermissionChange(role, 'update', checked === true)
                      }
                      aria-label={`${t('Update permission for')} ${role}`}
                    />
                  </TableCell>
                  <TableCell className={cn(d.pad, d.actMin, 'text-center')}>
                    <Checkbox
                      checked={actions.delete}
                      onCheckedChange={(checked) =>
                        handlePermissionChange(role, 'delete', checked === true)
                      }
                      aria-label={`${t('Delete permission for')} ${role}`}
                    />
                  </TableCell>
                  {withWrite && (
                    <TableCell className={cn(d.pad, d.actMin, 'text-center')}>
                      <Checkbox
                        checked={actions.write}
                        onCheckedChange={(checked) =>
                          handlePermissionChange(role, 'write', checked === true)
                        }
                        aria-label={`${t('Write permission for')} ${role}`}
                      />
                    </TableCell>
                  )}
                  <TableCell className={cn(d.pad, d.rmCol)}>
                    <Button
                      variant="ghost"
                      size="icon"
                      className={d.rmBtn}
                      onClick={() => handleRemoveRole(role)}
                      aria-label={`${t('Remove')} ${role} ${t('permissions')}`}
                    >
                      <X className={d.rmIcon} />
                    </Button>
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      </div>

      <AddRoleDropdown
        onAddSpecialRole={handleAddRole}
        onOpenUserModal={() => setUserModalOpen(true)}
        onOpenTeamModal={() => setTeamModalOpen(true)}
        onOpenMemberModal={() => setMemberModalOpen(true)}
        onOpenLabelModal={() => setLabelModalOpen(true)}
        onOpenCustomModal={() => setCustomModalOpen(true)}
        hasAny={hasAny}
        hasGuests={hasGuests}
        hasUsers={hasUsers}
      />

      {/* Modals */}
      <UserSelectionModal
        open={userModalOpen}
        onOpenChange={setUserModalOpen}
        onSelect={handleAddUsers}
        projectId={projectId}
        existingRoles={new Set(permissionsMap.keys())}
      />
      <TeamSelectionModal
        open={teamModalOpen}
        onOpenChange={setTeamModalOpen}
        onSelect={handleAddTeams}
        projectId={projectId}
        existingRoles={new Set(permissionsMap.keys())}
      />
      <MemberSelectionModal
        open={memberModalOpen}
        onOpenChange={setMemberModalOpen}
        onSelect={handleAddMembers}
        projectId={projectId}
        existingRoles={new Set(permissionsMap.keys())}
      />
      <LabelInputModal
        open={labelModalOpen}
        onOpenChange={setLabelModalOpen}
        onAdd={handleAddLabel}
      />
      <CustomRoleInputModal
        open={customModalOpen}
        onOpenChange={setCustomModalOpen}
        onAdd={handleAddCustom}
      />
    </div>
  )
})

/**
 * AddRoleDropdown - dropdown menu for adding roles
 */
interface AddRoleDropdownProps {
  onAddSpecialRole: (role: string) => void
  onOpenUserModal: () => void
  onOpenTeamModal: () => void
  onOpenMemberModal: () => void
  onOpenLabelModal: () => void
  onOpenCustomModal: () => void
  hasAny: boolean
  hasGuests: boolean
  hasUsers: boolean
  emptyState?: boolean
}

function AddRoleDropdown({
  onAddSpecialRole,
  onOpenUserModal,
  onOpenTeamModal,
  onOpenMemberModal,
  onOpenLabelModal,
  onOpenCustomModal,
  hasAny,
  hasGuests,
  hasUsers,
  emptyState = false,
}: AddRoleDropdownProps) {
  const t = useT()
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        {emptyState ? (
          <Button
            variant="secondary"
            size="icon"
            className="size-10"
            aria-label={t('Add role')}
          >
            <Plus className="size-4" />
          </Button>
        ) : (
          <Button variant="secondary" size="sm">
            <Plus className="size-4 me-1.5" />
            {t('Add role')}
          </Button>
        )}
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="w-56">
        <DropdownMenuItem
          onClick={() => onAddSpecialRole('any')}
          disabled={hasAny}
        >
          <span>{t('Any')}</span>
        </DropdownMenuItem>
        <DropdownMenuItem
          onClick={() => onAddSpecialRole('guests')}
          disabled={hasGuests}
        >
          <span>{t('All guests')}</span>
        </DropdownMenuItem>
        <DropdownMenuItem
          onClick={() => onAddSpecialRole('users')}
          disabled={hasUsers}
        >
          <span>{t('All users')}</span>
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem
          onSelect={() => openDialogAfterOverlayCloses(onOpenUserModal)}
        >
          <User className="size-4 me-2" />
          <span>{t('Select users')}</span>
        </DropdownMenuItem>
        <DropdownMenuItem
          onSelect={() => openDialogAfterOverlayCloses(onOpenTeamModal)}
        >
          <Building2 className="size-4 me-2" />
          <span>{t('Select teams')}</span>
        </DropdownMenuItem>
        <DropdownMenuItem
          onSelect={() => openDialogAfterOverlayCloses(onOpenMemberModal)}
        >
          <Users className="size-4 me-2" />
          <span>{t('Select memberships')}</span>
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem
          onSelect={() => openDialogAfterOverlayCloses(onOpenLabelModal)}
        >
          <Tag className="size-4 me-2" />
          <span>{t('Label')}</span>
        </DropdownMenuItem>
        <DropdownMenuItem
          onSelect={() => openDialogAfterOverlayCloses(onOpenCustomModal)}
        >
          <Code className="size-4 me-2" />
          <span>{t('Custom')}</span>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
