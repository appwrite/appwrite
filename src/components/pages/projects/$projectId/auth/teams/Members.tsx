import { useState, useEffect } from 'react'
import { useParams, Link } from '@tanstack/react-router'
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
import { Plus, Trash2, X, Info } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { Models } from '@appwrite.io/console'

const MEMBERSHIPS_PER_PAGE = 25

export function TeamMembers() {
  const { projectId, teamId } = useParams({
    strict: false,
  })

  const [page, setPage] = useState(1)
  const [search] = useState('')
  const [selectedMemberships, setSelectedMemberships] = useState<Set<string>>(
    new Set(),
  )
  const [createDialogOpen, setCreateDialogOpen] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [membershipToDelete, setMembershipToDelete] =
    useState<Models.Membership | null>(null)

  const { data: membershipsData, isLoading } = useTeamMemberships(
    projectId,
    teamId,
    page - 1,
    MEMBERSHIPS_PER_PAGE,
    search,
  )

  const createMembershipMutation = useCreateTeamMembership(projectId, teamId)
  const deleteMembershipMutation = useDeleteTeamMembership(projectId, teamId)

  const memberships = membershipsData?.memberships || []
  const total = membershipsData?.total || 0

  // Reset selection when page or search changes
  useEffect(() => {
    setSelectedMemberships(new Set())
  }, [page, search])

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

  const handleDeleteMembership = async (membershipId: string) => {
    if (!teamId) return

    try {
      await deleteMembershipMutation.mutateAsync(membershipId)
      toast.success('Member deleted successfully')
      setDeleteDialogOpen(false)
      setMembershipToDelete(null)
      setSelectedMemberships(new Set())
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : 'Failed to delete membership',
      )
    }
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

  const totalPages = Math.ceil(total / MEMBERSHIPS_PER_PAGE)

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between mb-6">
        <div>
          <h2 className="text-[15px] font-medium text-foreground">Members</h2>
          <p className="text-[12px] text-muted-foreground mt-1">
            {total} member{total !== 1 ? 's' : ''}
          </p>
        </div>
        <Button onClick={() => setCreateDialogOpen(true)}>
          <Plus className="h-4 w-4 mr-1.5" />
          Create membership
        </Button>
      </div>

      {isLoading ? (
        <div className="rounded-lg border border-border bg-card py-12 text-center">
          <div className="text-muted-foreground">Loading members...</div>
        </div>
      ) : memberships.length === 0 ? (
        <div className="rounded-lg border border-border bg-card py-12 text-center">
          <p className="text-muted-foreground mb-4">No memberships available</p>
          <Button onClick={() => setCreateDialogOpen(true)}>
            <Plus className="h-4 w-4 mr-1.5" />
            Create membership
          </Button>
        </div>
      ) : (
        <>
          <div className="rounded-lg border border-border bg-card">
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className="w-[40px] px-4">
                    <Checkbox
                      checked={
                        memberships.length > 0 &&
                        selectedMemberships.size === memberships.length
                      }
                      onCheckedChange={toggleAll}
                    />
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[220px]">
                    Name
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[200px]">
                    Roles
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px]">
                    Joined
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[80px]"></TableHead>
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
                        'cursor-pointer transition-colors',
                        selectedMemberships.has(membership.$id)
                          ? 'bg-sky-100 dark:bg-sky-950'
                          : 'hover:bg-muted/50',
                      )}
                    >
                      <TableCell onClick={(e) => e.stopPropagation()}>
                        <Checkbox
                          checked={selectedMemberships.has(membership.$id)}
                          onCheckedChange={() =>
                            toggleMembership(membership.$id)
                          }
                        />
                      </TableCell>
                      <TableCell>
                        <Link
                          to="/projects/$projectId/auth/$userId"
                          params={{
                            projectId: projectId as string,
                            userId: membership.userId,
                          }}
                          className="flex items-center gap-3 min-w-0"
                        >
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
                        </Link>
                      </TableCell>
                      <TableCell>
                        <div className="flex flex-wrap gap-1">
                          {roles.length > 0 ? (
                            roles.map((role, idx) => (
                              <Badge
                                key={idx}
                                variant="secondary"
                                className="text-[11px] font-medium"
                              >
                                {role}
                              </Badge>
                            ))
                          ) : (
                            <span className="text-[12px] text-muted-foreground">
                              -
                            </span>
                          )}
                        </div>
                      </TableCell>
                      <TableCell>
                        <DateTooltip
                          date={membership.$createdAt}
                          className="text-[12px] text-muted-foreground"
                        />
                      </TableCell>
                      <TableCell onClick={(e) => e.stopPropagation()}>
                        <Button
                          variant="ghost"
                          size="icon"
                          className="h-8 w-8"
                          onClick={() => {
                            setMembershipToDelete(membership)
                            setDeleteDialogOpen(true)
                          }}
                        >
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          </div>

          {selectedMemberships.size > 0 && (
            <div className="mt-4 flex items-center justify-between rounded-lg border border-border bg-card p-4">
              <span className="text-[13px] text-foreground">
                {selectedMemberships.size} member
                {selectedMemberships.size !== 1 ? 's' : ''} selected
              </span>
              <Button
                variant="destructive"
                size="sm"
                onClick={handleBulkDelete}
                disabled={deleteMembershipMutation.isPending}
              >
                <Trash2 className="h-4 w-4 mr-1.5" />
                Delete
              </Button>
            </div>
          )}

          {totalPages > 1 && (
            <Pagination
              currentPage={page}
              totalItems={total}
              pageSize={MEMBERSHIPS_PER_PAGE}
              pageSizeOptions={[10, 25, 50, 100]}
              onPageChange={setPage}
              onPageSizeChange={() => {}}
              itemLabel="members"
            />
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

      {/* Delete Membership Dialog */}
      {membershipToDelete && (
        <DeleteMembershipDialog
          open={deleteDialogOpen}
          onOpenChange={(open) => {
            setDeleteDialogOpen(open)
            if (!open) setMembershipToDelete(null)
          }}
          membership={membershipToDelete}
          onConfirm={() => handleDeleteMembership(membershipToDelete.$id)}
          isLoading={deleteMembershipMutation.isPending}
        />
      )}
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
                        variant="secondary"
                        className="text-[12px] font-medium"
                      >
                        {role}
                        <button
                          type="button"
                          onClick={() => handleRemoveRole(role)}
                          className="ml-1.5 hover:text-destructive"
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
                  any role you want.
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

// Delete Membership Dialog Component
interface DeleteMembershipDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  membership: Models.Membership
  onConfirm: () => void
  isLoading: boolean
}

function DeleteMembershipDialog({
  open,
  onOpenChange,
  membership,
  onConfirm,
  isLoading,
}: DeleteMembershipDialogProps) {
  const userName = membership.userName || membership.userEmail || 'this member'
  const teamName = membership.teamName || 'this team'

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>Delete member</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Are you sure you want to delete{' '}
            <strong>
              {userName} · {teamName}
            </strong>
            ? This action cannot be undone.
          </DialogDescription>
        </DialogHeader>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => onOpenChange(false)}
            disabled={isLoading}
          >
            Cancel
          </Button>
          <Button
            variant="destructive"
            size="sm"
            className="h-9 text-[13px]"
            onClick={onConfirm}
            disabled={isLoading}
          >
            Delete
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
