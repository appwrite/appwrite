'use client'

import * as React from 'react'
import { useState, useEffect, useCallback, useRef } from 'react'
import { Plus, X, Users, User, Building2, Tag, Code } from 'lucide-react'
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
import { useProjectUsers, useProjectTeams } from '@/lib/react-query/hooks'
import { useParams } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import { EmptyState } from '@/components/global/shared/EmptyState'

export interface PermissionsEditorProps {
  permissions: string[]
  onPermissionsChange: (permissions: string[]) => void
  withCreate?: boolean
  /** When true, only parse/display "execute" permission (e.g. for functions) */
  executeOnly?: boolean
  projectId?: string
}

interface PermissionActions {
  create: boolean
  read: boolean
  update: boolean
  delete: boolean
  execute: boolean
}

type PermissionAction = 'create' | 'read' | 'update' | 'delete' | 'execute'

const EMPTY_ACTIONS: PermissionActions = {
  create: false,
  read: false,
  update: false,
  delete: false,
  execute: false,
}

function hasAnyPermission(
  actions: PermissionActions,
  executeOnly: boolean,
): boolean {
  if (executeOnly) return actions.execute
  return actions.create || actions.read || actions.update || actions.delete
}

/**
 * Parse permission strings into a map of role -> actions
 */
function parsePermissions(
  perms: string[],
  executeOnly?: boolean,
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
      if (executeOnly) {
        if (action === 'execute') actions.execute = true
      } else if (
        action === 'create' ||
        action === 'read' ||
        action === 'update' ||
        action === 'delete'
      ) {
        actions[action] = true
      }
    } else if (executeOnly && perm.trim()) {
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
  executeOnly?: boolean,
): string[] {
  const perms: string[] = []

  if (executeOnly) {
    // Function execute attribute expects plain role names (e.g. ["any"], ["users"])
    roleMap.forEach((actions, role) => {
      if (actions.execute) {
        perms.push(role)
      }
    })
    return perms
  }

  roleMap.forEach((actions, role) => {
    ;(['create', 'read', 'update', 'delete'] as const).forEach((action) => {
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
  // Special roles
  if (role === 'any') {
    return <span className="text-[13px] font-medium text-foreground">Any</span>
  }

  if (role === 'guests') {
    return (
      <span className="text-[13px] font-medium text-foreground">
        All guests
      </span>
    )
  }

  if (role === 'users') {
    return (
      <span className="text-[13px] font-medium text-foreground">All users</span>
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
          <Badge
            variant="secondary"
            className="text-[10px] px-1.5 py-0 shrink-0"
          >
            User
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
  const { teams } = useProjectTeams(projectId || null, 0, 100, '')
  const team = teams.find((t) => t.id === teamId)

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
          <Badge
            variant="secondary"
            className="text-[10px] px-1.5 py-0 shrink-0"
          >
            Team
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
          <Badge
            variant="secondary"
            className="text-[10px] px-1.5 py-0 shrink-0"
          >
            Label
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
          <Badge
            variant="secondary"
            className="text-[10px] px-1.5 py-0 shrink-0"
          >
            Custom
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
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>Select Users</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Choose one or more users to add permissions for.
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0">
          <div className="space-y-4">
            <Input
              placeholder="Search users by name, email, or ID..."
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(0)
              }}
            />

            <div className="max-h-[300px] overflow-y-auto space-y-1">
              {isLoading ? (
                <div className="text-center py-8 text-sm text-muted-foreground">
                  Loading users...
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
            Cancel
          </Button>
          <Button onClick={handleAdd} disabled={selectedUserIds.size === 0}>
            Add {selectedUserIds.size > 0 ? `(${selectedUserIds.size})` : ''}
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
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>Select Teams</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Choose one or more teams to add permissions for.
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0">
          <div className="space-y-4">
            <Input
              placeholder="Search teams by name or ID..."
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(0)
              }}
            />

            <div className="max-h-[300px] overflow-y-auto space-y-1">
              {isLoading ? (
                <div className="text-center py-8 text-sm text-muted-foreground">
                  Loading teams...
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
            Cancel
          </Button>
          <Button onClick={handleAdd} disabled={selectedTeamIds.size === 0}>
            Add {selectedTeamIds.size > 0 ? `(${selectedTeamIds.size})` : ''}
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
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>Add Label</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Enter a label name to create a label-based permission.
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0">
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="label-name">Label Name</Label>
              <Input
                id="label-name"
                placeholder="e.g., premium, admin, moderator"
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
            Cancel
          </Button>
          <Button onClick={handleAdd} disabled={!labelName.trim()}>
            Add
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

/** Valid permission format: user:ID, user:ID/role, team:ID, or team:ID/role */
function isValidPermissionFormat(value: string): boolean {
  const trimmed = value.trim()
  if (!trimmed) return false
  // user:userId or user:userId/roleName
  if (/^user:[^/]+(\/.+)?$/.test(trimmed)) return true
  // team:teamId or team:teamId/roleName
  if (/^team:[^/]+(\/.+)?$/.test(trimmed)) return true
  return false
}

/**
 * CustomRoleInputModal - modal for entering a role by permission string (user: or team: format)
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
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>Add by role string</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Grant access using a user or team ID. Use{' '}
            <code className="rounded bg-muted px-1 py-0.5 text-[12px]">
              user:[USER_ID]
            </code>{' '}
            or{' '}
            <code className="rounded bg-muted px-1 py-0.5 text-[12px]">
              team:[TEAM_ID]/[ROLE]
            </code>
            .
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0">
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="custom-role">Permission string</Label>
              <Input
                id="custom-role"
                placeholder="user:USER_ID or team:TEAM_ID/ROLE"
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
                <p className="text-[12px] text-destructive flex items-center gap-1.5">
                  <span>Use format</span>
                  <code className="rounded bg-destructive/10 px-1 py-0.5">
                    user:USER_ID
                  </code>
                  <span>or</span>
                  <code className="rounded bg-destructive/10 px-1 py-0.5">
                    team:TEAM_ID/ROLE
                  </code>
                </p>
              )}
            </div>
          </div>
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={handleCancel}>
            Cancel
          </Button>
          <Button onClick={handleAdd} disabled={!isValid}>
            Add
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

/**
 * Main PermissionsEditor component
 */
export function PermissionsEditor({
  permissions,
  onPermissionsChange,
  withCreate = false,
  executeOnly = false,
  projectId: projectIdProp,
}: PermissionsEditorProps) {
  const params = useParams({ strict: false })
  const projectId = projectIdProp || (params.projectId as string | undefined)

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
      const newMap = parsePermissions(permissions, executeOnly)
      setPermissionsMap(newMap)
      // Initialize rolesWithPermissions with roles that already have permissions
      const rolesWithPerms = new Set<string>()
      newMap.forEach((actions, role) => {
        if (hasAnyPermission(actions, executeOnly)) {
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
      const newMap = parsePermissions(permissions, executeOnly)
      setPermissionsMap(newMap)
      // Initialize rolesWithPermissions
      const rolesWithPerms = new Set<string>()
      newMap.forEach((actions, role) => {
        if (hasAnyPermission(actions, executeOnly)) {
          rolesWithPerms.add(role)
        }
      })
      setRolesWithPermissions(rolesWithPerms)
      return
    }

    // Permissions changed externally - merge with current state to preserve newly added roles
    const newMap = parsePermissions(permissions, executeOnly)

    // If current map is empty and we have new permissions, just set them directly
    setPermissionsMap((prevMap) => {
      // If previous map is empty, just use the new map (first time loading permissions)
      if (prevMap.size === 0) {
        const rolesWithPerms = new Set<string>()
        newMap.forEach((actions, role) => {
          if (hasAnyPermission(actions, executeOnly)) {
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
        if (hasAnyPermission(actions, executeOnly)) {
          rolesWithPerms.add(role)
        }
      })
      setRolesWithPermissions(rolesWithPerms)

      return mergedMap
    })
  }, [permissions, executeOnly])

  // Export permissions when map changes (but not during initialization)
  useEffect(() => {
    // Skip export during initial mount
    if (isInitialMountRef.current) {
      return
    }

    const exported = exportPermissions(permissionsMap, executeOnly)
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
  }, [permissionsMap, permissions, onPermissionsChange, executeOnly])

  const handlePermissionChange = useCallback(
    (role: string, action: PermissionAction, enabled: boolean) => {
      setPermissionsMap((prev) => {
        const newMap = new Map(prev)
        if (!newMap.has(role)) {
          newMap.set(role, { ...EMPTY_ACTIONS })
        }
        const actions = newMap.get(role)!
        const hadPermissionsBefore = hasAnyPermission(actions, executeOnly)
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
        const allDisabled = !hasAnyPermission(actions, executeOnly)

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
    [executeOnly],
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
      <div className="flex flex-col items-center justify-center gap-4 py-12">
        <AddRoleDropdown
          onAddSpecialRole={handleAddRole}
          onOpenUserModal={() => setUserModalOpen(true)}
          onOpenTeamModal={() => setTeamModalOpen(true)}
          onOpenLabelModal={() => setLabelModalOpen(true)}
          onOpenCustomModal={() => setCustomModalOpen(true)}
          hasAny={hasAny}
          hasGuests={hasGuests}
          hasUsers={hasUsers}
          emptyState
        />
        <p className="text-sm text-muted-foreground">
          {executeOnly
            ? 'Add roles to choose who can execute'
            : 'Add a role to get started'}
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

  // Execute-only: simple list of roles (who can execute) — no table or checkboxes
  if (executeOnly) {
    const rolesWithExecute = roles.filter(
      (role) => permissionsMap.get(role)?.execute,
    )
    return (
      <div className="space-y-4">
        <div className="flex flex-wrap gap-2">
          {rolesWithExecute.map((role) => (
            <div
              key={role}
              className="flex items-center gap-2 rounded-lg border border-border bg-muted/30 pl-3 pr-1 py-2 min-w-0"
            >
              <RoleDisplay role={role} projectId={projectId} />
              <Button
                variant="ghost"
                size="icon"
                className="size-8 shrink-0"
                onClick={() => handleRemoveRole(role)}
                aria-label={`Remove ${role} from execute list`}
              >
                <X className="size-4" />
              </Button>
            </div>
          ))}
        </div>
        <AddRoleDropdown
          onAddSpecialRole={handleAddRole}
          onOpenUserModal={() => setUserModalOpen(true)}
          onOpenTeamModal={() => setTeamModalOpen(true)}
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
    <div className="space-y-4">
      <div className="overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider min-w-[220px]">
                Role
              </TableHead>
              {withCreate && (
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center min-w-[64px]">
                  Create
                </TableHead>
              )}
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center min-w-[64px]">
                Read
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center min-w-[64px]">
                Update
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center min-w-[64px]">
                Delete
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[40px]"></TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {roles.map((role) => {
              const actions = permissionsMap.get(role)!
              return (
                <TableRow key={role}>
                  <TableCell className="px-4 py-3 min-w-[220px]">
                    <RoleDisplay role={role} projectId={projectId} />
                  </TableCell>
                  {withCreate && (
                    <TableCell className="px-4 py-3 min-w-[64px] text-center">
                      <Checkbox
                        checked={actions.create}
                        onCheckedChange={(checked) =>
                          handlePermissionChange(
                            role,
                            'create',
                            checked === true,
                          )
                        }
                        aria-label={`Create permission for ${role}`}
                      />
                    </TableCell>
                  )}
                  <TableCell className="px-4 py-3 min-w-[64px] text-center">
                    <Checkbox
                      checked={actions.read}
                      onCheckedChange={(checked) =>
                        handlePermissionChange(role, 'read', checked === true)
                      }
                      aria-label={`Read permission for ${role}`}
                    />
                  </TableCell>
                  <TableCell className="px-4 py-3 min-w-[64px] text-center">
                    <Checkbox
                      checked={actions.update}
                      onCheckedChange={(checked) =>
                        handlePermissionChange(role, 'update', checked === true)
                      }
                      aria-label={`Update permission for ${role}`}
                    />
                  </TableCell>
                  <TableCell className="px-4 py-3 min-w-[64px] text-center">
                    <Checkbox
                      checked={actions.delete}
                      onCheckedChange={(checked) =>
                        handlePermissionChange(role, 'delete', checked === true)
                      }
                      aria-label={`Delete permission for ${role}`}
                    />
                  </TableCell>
                  <TableCell className="px-4 py-3 w-[40px]">
                    <Button
                      variant="ghost"
                      size="icon"
                      className="size-8"
                      onClick={() => handleRemoveRole(role)}
                      aria-label={`Remove ${role} permissions`}
                    >
                      <X className="size-4" />
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

/**
 * AddRoleDropdown - dropdown menu for adding roles
 */
interface AddRoleDropdownProps {
  onAddSpecialRole: (role: string) => void
  onOpenUserModal: () => void
  onOpenTeamModal: () => void
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
  onOpenLabelModal,
  onOpenCustomModal,
  hasAny,
  hasGuests,
  hasUsers,
  emptyState = false,
}: AddRoleDropdownProps) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        {emptyState ? (
          <Button variant="secondary" size="icon" className="size-10">
            <Plus className="size-4" />
          </Button>
        ) : (
          <Button variant="secondary" size="sm">
            <Plus className="size-4 mr-1.5" />
            Add role
          </Button>
        )}
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="w-56">
        <DropdownMenuItem
          onClick={() => onAddSpecialRole('any')}
          disabled={hasAny}
        >
          <span>Any</span>
        </DropdownMenuItem>
        <DropdownMenuItem
          onClick={() => onAddSpecialRole('guests')}
          disabled={hasGuests}
        >
          <span>All guests</span>
        </DropdownMenuItem>
        <DropdownMenuItem
          onClick={() => onAddSpecialRole('users')}
          disabled={hasUsers}
        >
          <span>All users</span>
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem onClick={onOpenUserModal}>
          <User className="size-4 mr-2" />
          <span>Select users</span>
        </DropdownMenuItem>
        <DropdownMenuItem onClick={onOpenTeamModal}>
          <Building2 className="size-4 mr-2" />
          <span>Select teams</span>
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem onClick={onOpenLabelModal}>
          <Tag className="size-4 mr-2" />
          <span>Label</span>
        </DropdownMenuItem>
        <DropdownMenuItem onClick={onOpenCustomModal}>
          <Code className="size-4 mr-2" />
          <span>Custom</span>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
