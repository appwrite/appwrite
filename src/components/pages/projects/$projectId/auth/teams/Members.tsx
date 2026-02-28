import { useState, useEffect } from 'react'
import { useParams } from '@tanstack/react-router'
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
import { MembershipUpdateDrawer } from '../_components/MembershipUpdateDrawer'
import { Plus, Trash2, X, Info, Loader2, Users } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { Models } from '@appwrite.io/console'

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

  const handleCreateMembership = async (data: {
    email: string
    name?: string
    roles: string[]
  }) => {
    if (!teamId) return

    const url = `${window.location.origin}/invite`

    try {
      await createMembershipMutation.mutateAsync({
        email: data.email,
        name: data.name,
        roles: data.roles,
        url,
      })

      const successName = data.name || data.email
      toast.success(`${successName} created successfully`)
      setCreateDialogOpen(false)
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : 'Failed to create membership',
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
        `Deleted ${selectedMemberships.size} member${selectedMemberships.size !== 1 ? 's' : ''}`,
      )
      setSelectedMemberships(new Set())
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : 'Failed to delete members',
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
            <h2 className="text-[15px] font-medium text-foreground">Members</h2>
            <p className="text-[12px] text-muted-foreground mt-1">
              {total} member{total !== 1 ? 's' : ''}
            </p>
          </div>
          <Button onClick={() => setCreateDialogOpen(true)}>
            <Plus className="h-4 w-4 mr-1.5" />
            Invite member
          </Button>
        </div>
      )}

      {isLoading ? (
        <div className="rounded-lg border border-border bg-card py-12 text-center">
          <Loader2 className="mx-auto h-5 w-5 animate-spin text-muted-foreground" />
          <p className="mt-2 text-[13px] text-muted-foreground">
            Loading members...
          </p>
        </div>
      ) : memberships.length === 0 ? (
        <div className="space-y-4">
          <EmptyState
            icon={Users}
            title="No memberships available"
            description={
              search
                ? 'No members match your search.'
                : 'Invite members to this team to get started.'
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
                <Plus className="mr-1.5 h-4 w-4" />
                Invite member
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
                    Name
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    Status
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    Roles
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    Joined
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {memberships.map((membership) => {
                  const userName = membership.userName || '-'
                  const userEmail = membership.userEmail || ''
                  const roles = membership.roles || []

                  return (
                    <TableRow
                      key={membership.$id}
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
                          {membership.confirm ? 'Active' : 'Pending'}
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
            <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
              <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
                <Badge variant="secondary" className="h-6 px-2.5">
                  {selectedMemberships.size} member
                  {selectedMemberships.size !== 1 ? 's' : ''} selected
                </Badge>
                <div className="flex items-center gap-2">
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setSelectedMemberships(new Set())}
                    className="h-8 text-xs"
                  >
                    Cancel
                  </Button>
                  <Button
                    variant="destructive"
                    size="sm"
                    onClick={handleBulkDelete}
                    disabled={deleteMembershipMutation.isPending}
                    className="h-8 gap-2"
                  >
                    Delete
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
  onSubmit: (data: { email: string; name?: string; roles: string[] }) => void
  isLoading: boolean
}

function CreateMembershipDialog({
  open,
  onOpenChange,
  onSubmit,
  isLoading,
}: CreateMembershipDialogProps) {
  const [email, setEmail] = useState('')
  const [name, setName] = useState('')
  const [roles, setRoles] = useState<string[]>([])
  const [roleInput, setRoleInput] = useState('')

  const handleOpenChange = (newOpen: boolean) => {
    if (!newOpen) {
      setEmail('')
      setName('')
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
    if (!email.trim() || roles.length === 0) {
      return
    }
    onSubmit({ email: email.trim(), name: name.trim() || undefined, roles })
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>Create membership</DialogTitle>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0">
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="email">
                Email <span className="text-destructive">*</span>
              </Label>
              <Input
                id="email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="Enter email"
                autoFocus
                autoComplete="off"
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="name">Name</Label>
              <Input
                id="name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Enter name"
                autoComplete="off"
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="roles">
                Roles <span className="text-destructive">*</span>
              </Label>
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
                    placeholder="Add roles"
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
                        className="text-[10px] shrink-0 pr-1"
                      >
                        {role}
                        <button
                          type="button"
                          onClick={() => handleRemoveRole(role)}
                          className="ml-0.5 hover:text-destructive rounded p-0.5"
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
                  Roles are used to manage access permissions. You can create
                  any role you want.{' '}
                  <a
                    href="https://appwrite.io/docs/advanced/platform/permissions"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-primary hover:underline"
                  >
                    Learn more about permissions
                  </a>
                </AlertDescription>
              </Alert>
            </div>
          </div>
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => handleOpenChange(false)}
            disabled={isLoading}
          >
            Cancel
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={!email.trim() || roles.length === 0 || isLoading}
          >
            Create
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
