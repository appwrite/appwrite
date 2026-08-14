import { useState, useEffect, useMemo } from 'react'
import { Link, useParams } from '@tanstack/react-router'
import {
  useTeamMemberships,
  useCreateTeamMembership,
  useDeleteTeamMembership,
} from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Pagination } from '@/components/global/shared/Pagination'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Checkbox } from '@/components/ui/checkbox'
import { Badge } from '@/components/ui/badge'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { UserSelector } from '@/components/global/shared/UserSelector'
import { MembershipContextMenu } from '../_components/MembershipContextMenu'
import { MembershipUpdateDrawer } from '../_components/MembershipUpdateDrawer'
import { Plus, Trash2, X, Info, Loader2, Users } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { Models } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'

const DEFAULT_PAGE_SIZE = 25

export interface TeamMembersProps {
  /** When provided with onCreateDialogOpenChange, search/filter is controlled by parent (e.g. ServiceHeader) */
  searchValue?: string
  onSearchChange?: (value: string) => void
  createDialogOpen?: boolean
  onCreateDialogOpenChange?: (open: boolean) => void
}

export function TeamMembers({
  searchValue: searchValueProp,
  onSearchChange: onSearchChangeProp,
  createDialogOpen: createDialogOpenProp,
  onCreateDialogOpenChange: onCreateDialogOpenChangeProp,
}: TeamMembersProps = {}) {
  const t = useT()
  const { projectId, teamId } = useParams({
    strict: false,
  })

  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)
  const [internalSearch, setInternalSearch] = useState('')
  const [selectedMemberships, setSelectedMemberships] = useState<Set<string>>(
    new Set(),
  )
  const [internalCreateDialogOpen, setInternalCreateDialogOpen] =
    useState(false)
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [selectedMembership, setSelectedMembership] =
    useState<Models.Membership | null>(null)

  const search =
    searchValueProp !== undefined ? searchValueProp : internalSearch
  const createDialogOpen =
    createDialogOpenProp !== undefined
      ? createDialogOpenProp
      : internalCreateDialogOpen
  const setCreateDialogOpen =
    onCreateDialogOpenChangeProp ?? setInternalCreateDialogOpen
  const hasHeaderInParent =
    searchValueProp !== undefined && createDialogOpenProp !== undefined

  const { data: membershipsData, isLoading } = useTeamMemberships(
    projectId,
    teamId,
    page - 1,
    pageSize,
    search,
  )

  const createMembershipMutation = useCreateTeamMembership(projectId, teamId)
  const deleteMembershipMutation = useDeleteTeamMembership(projectId, teamId)

  const memberships = membershipsData?.memberships || []
  const total = membershipsData?.total || 0

  // Reset selection and page when search changes
  useEffect(() => {
    setSelectedMemberships(new Set())
  }, [page, search])
  useEffect(() => {
    setPage(1)
  }, [search])

  const handleCreateMembership = async (data: { userId: string; roles: string[] }) => {
    if (!teamId) return

    try {
      await createMembershipMutation.mutateAsync({
        userId: data.userId,
        roles: data.roles,
      })

      toast.success(t('Member added successfully'))
      setCreateDialogOpen(false)
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : t('Failed to add member'),
      )
    }
  }

  const openDrawer = (membership: Models.Membership) => {
    setSelectedMembership(membership)
    setDrawerOpen(true)
  }

  const handleBulkDelete = async () => {
    if (selectedMemberships.size === 0 || !teamId) return

    try {
      await Promise.all(
        Array.from(selectedMemberships).map((membershipId) =>
          deleteMembershipMutation.mutateAsync(membershipId),
        ),
      )
      toast.success(
        selectedMemberships.size !== 1
          ? `${selectedMemberships.size} ${t('members deleted successfully')}`
          : t('Member deleted successfully'),
      )
      setSelectedMemberships(new Set())
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : t('Failed to delete members'),
      )
    }
  }

  const toggleMembership = (membershipId: string) => {
    setSelectedMemberships((prev) => {
      const next = new Set(prev)
      if (next.has(membershipId)) {
        next.delete(membershipId)
      } else {
        next.add(membershipId)
      }
      return next
    })
  }

  const toggleAll = () => {
    if (selectedMemberships.size === memberships.length) {
      setSelectedMemberships(new Set())
    } else {
      setSelectedMemberships(new Set(memberships.map((m) => m.$id)))
    }
  }

  const totalPages = Math.ceil(total / pageSize)

  return (
    <div className="space-y-4">
      {!hasHeaderInParent && (
        <div className="flex items-center justify-between mb-6">
          <div>
            <h2 className="text-[15px] font-medium text-foreground">
              {t('Members')}
            </h2>
            <p className="text-[12px] text-muted-foreground mt-1">
              {total} {total !== 1 ? t('members') : t('member')}
            </p>
          </div>
          <Button onClick={() => setCreateDialogOpen(true)}>
            <Plus className="h-4 w-4 me-1.5" />
            {t('Add member')}
          </Button>
        </div>
      )}

      {isLoading ? (
        <div className="rounded-lg border border-border bg-card py-12 text-center">
          <Loader2 className="mx-auto h-5 w-5 animate-spin text-muted-foreground" />
          <p className="mt-2 text-[13px] text-muted-foreground">
            {t('Loading members...')}
          </p>
        </div>
      ) : memberships.length === 0 ? (
        <div className="space-y-4">
          <EmptyState
            icon={Users}
            title={t('No memberships available')}
            description={
              search
                ? t('No members match your search.')
                : t('Invite members to this team to get started.')
            }
            isEmpty={!search}
            hasFilters={!!search}
            variant="card"
            iconSize="md"
          />
          {!hasHeaderInParent && !search && (
            <div className="flex justify-center">
              <Button
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => setCreateDialogOpen(true)}
              >
                <Plus className="me-1.5 h-4 w-4" />
                {t('Add member')}
              </Button>
            </div>
          )}
        </div>
      ) : (
        <>
          <div className="rounded-lg border border-border bg-card overflow-hidden">
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className="w-[40px] px-4 py-3">
                    <Checkbox
                      checked={
                        memberships.length > 0 &&
                        selectedMemberships.size === memberships.length
                      }
                      onCheckedChange={toggleAll}
                    />
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Name')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Status')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Roles')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Joined')}
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {memberships.map((membership) => {
                  const userName = membership.userName || '-'
                  const userEmail = membership.userEmail || ''
                  const roles = membership.roles || []

                  return (
                    <MembershipContextMenu
                      key={membership.$id}
                      projectId={projectId!}
                      membership={membership}
                      onOpenMembership={() => openDrawer(membership)}
                    >
                      <TableRow
                        className={cn(
                          'cursor-pointer transition-colors border-b border-border/50',
                          selectedMemberships.has(membership.$id)
                            ? 'bg-muted'
                            : 'hover:bg-muted/30',
                        )}
                        onClick={() => openDrawer(membership)}
                      >
                        <TableCell
                          className="w-[40px] px-4 py-3"
                          onClick={(e) => e.stopPropagation()}
                        >
                          <Checkbox
                            checked={selectedMemberships.has(membership.$id)}
                            onCheckedChange={() =>
                              toggleMembership(membership.$id)
                            }
                          />
                        </TableCell>
                        <TableCell
                          className="px-4 py-3"
                          onClick={() => openDrawer(membership)}
                        >
                          <div className="flex items-center gap-3 min-w-0">
                            <InitialsAvatar
                              name={userName !== '-' ? userName : userEmail}
                              size="md"
                            />
                            <div className="flex-1 min-w-0">
                              <p className="truncate text-[13px] font-medium text-foreground">
                                {userName}
                              </p>
                              {userEmail && (
                                <p className="mt-0.5 truncate text-[12px] text-muted-foreground">
                                  {userEmail}
                                </p>
                              )}
                            </div>
                          </div>
                        </TableCell>
                        <TableCell
                          className="px-4 py-3"
                          onClick={() => openDrawer(membership)}
                        >
                          <Badge
                            variant={membership.confirm ? 'active' : 'pending'}
                            className="text-[10px] shrink-0"
                          >
                            {membership.confirm ? t('Active') : t('Pending')}
                          </Badge>
                        </TableCell>
                        <TableCell
                          className="px-4 py-3"
                          onClick={() => openDrawer(membership)}
                        >
                          <div className="flex flex-wrap gap-1.5 items-center">
                            {roles.length > 0 ? (
                              <>
                                {roles.slice(0, 2).map((role, idx) => (
                                  <Badge
                                    key={idx}
                                    variant="info"
                                    className="text-[10px] shrink-0"
                                  >
                                    {role}
                                  </Badge>
                                ))}
                                {roles.length > 2 && (
                                  <Badge
                                    variant="info"
                                    className="text-[10px] shrink-0"
                                  >
                                    +{roles.length - 2}
                                  </Badge>
                                )}
                              </>
                            ) : (
                              <span className="text-[12px] text-muted-foreground">
                                -
                              </span>
                            )}
                          </div>
                        </TableCell>
                        <TableCell
                          className="px-4 py-3"
                          onClick={() => openDrawer(membership)}
                        >
                          <DateTooltip
                            date={membership.$createdAt}
                            className="text-[12px] text-muted-foreground"
                          />
                        </TableCell>
                      </TableRow>
                    </MembershipContextMenu>
                  )
                })}
              </TableBody>
            </Table>
          </div>

          {memberships.length > 0 && (
            <Pagination
              currentPage={page}
              totalItems={total}
              pageSize={pageSize}
              pageSizeOptions={[10, 25, 50, 100]}
              onPageChange={setPage}
              onPageSizeChange={(size) => {
                setPageSize(size)
                setPage(1)
              }}
              itemLabel="members"
            />
          )}

          {selectedMemberships.size > 0 && (
            <div className="fixed bottom-4 start-1/2 z-50 -translate-x-1/2">
              <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
                <Badge variant="secondary" className="h-6 px-2.5">
                  {selectedMemberships.size}{' '}
                  {selectedMemberships.size !== 1
                    ? t('members selected')
                    : t('member selected')}
                </Badge>
                <div className="flex items-center gap-2">
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setSelectedMemberships(new Set())}
                    className="h-8 text-xs"
                  >
                    {t('Cancel')}
                  </Button>
                  <Button
                    variant="destructive"
                    size="sm"
                    onClick={handleBulkDelete}
                    disabled={deleteMembershipMutation.isPending}
                    className="h-8 gap-2"
                  >
                    {t('Delete')}
                  </Button>
                </div>
              </div>
            </div>
          )}
        </>
      )}

      {/* Create Membership Dialog */}
      <CreateMembershipDialog
        open={createDialogOpen}
        onOpenChange={setCreateDialogOpen}
        onSubmit={handleCreateMembership}
        isLoading={createMembershipMutation.isPending}
        projectId={projectId ?? ''}
        memberships={memberships}
      />

      {/* Update membership drawer */}
      <MembershipUpdateDrawer
        open={drawerOpen}
        onOpenChange={setDrawerOpen}
        membership={selectedMembership}
        projectId={projectId!}
        context="team"
      />
    </div>
  )
}

// Create Membership Dialog Component
interface CreateMembershipDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSubmit: (data: { userId: string; roles: string[] }) => void
  isLoading: boolean
  projectId: string
  memberships: Models.Membership[]
}

function CreateMembershipDialog({
  open,
  onOpenChange,
  onSubmit,
  isLoading,
  projectId,
  memberships,
}: CreateMembershipDialogProps) {
  const t = useT()
  const [selectedUserId, setSelectedUserId] = useState('')
  const [roles, setRoles] = useState<string[]>([])
  const [roleInput, setRoleInput] = useState('')

  const existingMemberUserIds = useMemo(
    () =>
      new Set(memberships.map((membership) => membership.userId).filter(Boolean)),
    [memberships],
  )

  const handleOpenChange = (newOpen: boolean) => {
    if (!newOpen) {
      setSelectedUserId('')
      setRoles([])
      setRoleInput('')
    }
    onOpenChange(newOpen)
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

  const handleSubmit = () => {
    if (!selectedUserId || roles.length === 0) {
      return
    }
    onSubmit({ userId: selectedUserId, roles })
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Add member')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Select an existing user and assign team roles.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0">
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="member-user">
                {t('User')} <span className="text-destructive">*</span>
              </Label>
              {open ? (
                <UserSelector
                  projectId={projectId}
                  value={selectedUserId}
                  onValueChange={setSelectedUserId}
                  placeholder={t('Select a user')}
                  excludeIds={existingMemberUserIds}
                />
              ) : null}
            </div>

            <div className="space-y-2">
              <Label htmlFor="roles">{t('Roles')}</Label>
              <div className="space-y-2">
                <div className="flex gap-2">
                  <Input
                    id="roles"
                    value={roleInput}
                    onChange={(e) => setRoleInput(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter' && roleInput.trim()) {
                        e.preventDefault()
                        handleAddRole()
                      }
                    }}
                    placeholder={t('Add roles')}
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
                  <div className="flex flex-wrap gap-2">
                    {roles.map((role) => (
                      <Badge
                        key={role}
                        variant="info"
                        className="text-[10px] shrink-0 pe-1"
                      >
                        {role}
                        <button
                          type="button"
                          onClick={() => handleRemoveRole(role)}
                          className="ms-0.5 hover:text-foreground rounded p-0.5"
                        >
                          <X className="h-3 w-3" />
                        </button>
                      </Badge>
                    ))}
                  </div>
                )}
              </div>
              <Alert>
                <Info className="h-4 w-4" />
                <AlertDescription className="text-[12px]">
                  {t('Roles are used to manage access permissions.')}{' '}
                  <DocsRouteLink className="link-neutral" href="/docs/advanced/platform/permissions">
                    {t('Learn more about permissions')}
                  </DocsRouteLink>
                </AlertDescription>
              </Alert>
            </div>

            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-3">
                <h3 className="text-[14px] font-semibold text-foreground">
                  {t('Need a new user?')}
                </h3>
              </div>
              <div className="border-t border-border" />
              <div className="px-6 py-3">
                <p className="text-[13px] text-muted-foreground">
                  {t(
                    'Create the user in Users first, then add them to this team.',
                  )}
                </p>
              </div>
              <div className="px-6 py-3 border-t border-border bg-muted/30">
                <Link
                  to="/projects/$projectId/auth/users"
                  params={{ projectId }}
                  onClick={() => handleOpenChange(false)}
                >
                  <Button variant="outline" size="sm" className="h-9 text-[13px]">
                    {t('Go to users')}
                  </Button>
                </Link>
              </div>
            </div>
          </div>
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => handleOpenChange(false)}
            disabled={isLoading}
          >
            {t('Cancel')}
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={!selectedUserId || roles.length === 0 || isLoading}
          >
            {t('Add member')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
