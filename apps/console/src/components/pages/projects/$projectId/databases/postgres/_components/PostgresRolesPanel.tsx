import { useEffect, useMemo, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { useDatabaseTableOperationsAccess } from '../../_components/DatabaseOperationsLockContext'
import {
  useExecutePostgresSql,
  usePostgresRoles,
} from '@/lib/react-query/hooks'
import {
  buildPostgresDropRoleSql,
  canUpdatePostgresRole,
  formatPostgresRoleConnectionLimit,
  formatPostgresRoleMembership,
  isPostgresBuiltinRole,
  isPostgresProtectedRole,
  isPostgresRoleFlag,
  type PostgresRoleRow,
} from '@/lib/postgres-roles'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { Pencil, Plus, Trash2, Users } from 'lucide-react'
import { PostgresRoleDrawer } from './PostgresRoleDrawer'
import { matchesPostgresLocalSearch } from './postgres-spreadsheet-chrome'
import { useT } from '@/lib/i18n/translate'
import { SPREADSHEET_SCROLL_LAYER_CLASS } from '@/lib/layout/spreadsheet-sticky'
import {
  openDialogAfterOverlayCloses,
  closeDialogBeforeOverlayUnmount,
} from '@/lib/utils/overlay-lock'

type PostgresRolesPanelProps = {
  databaseId: string
  searchValue: string
  createOpen: boolean
  onCreateOpenChange: (open: boolean) => void
}

const rolesTableClassName = 'w-full min-w-[62rem] table-fixed'

const rolesTableScrollWrapperClassName = `${SPREADSHEET_SCROLL_LAYER_CLASS} min-w-[62rem]`

const ROLES_TABLE_HEAD_CLASS =
  'sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]'

function RolesTableColGroup() {
  return (
    <colgroup>
      <col className="w-[16rem]" />
      <col className="w-[5rem]" />
      <col className="w-[6.5rem]" />
      <col className="w-[6.5rem]" />
      <col className="w-[10.5rem]" />
      <col className="w-[7.5rem]" />
      <col />
      <col className="w-[100px]" />
    </colgroup>
  )
}

function RolesTableHead() {
  const t = useT()
  return (
    <TableHeader>
      <TableRow className="border-b border-border hover:bg-transparent">
        <TableHead
          className={`${ROLES_TABLE_HEAD_CLASS} ps-6 sm:ps-8`}
          title={t('Role name')}
        >
          {t('Role')}
        </TableHead>
        <TableHead className={ROLES_TABLE_HEAD_CLASS} title={t('Can login')}>
          {t('Login')}
        </TableHead>
        <TableHead
          className={ROLES_TABLE_HEAD_CLASS}
          title={t('Can create roles')}
        >
          {t('Create roles')}
        </TableHead>
        <TableHead
          className={ROLES_TABLE_HEAD_CLASS}
          title={t('Can create databases')}
        >
          {t('Create DB')}
        </TableHead>
        <TableHead
          className={ROLES_TABLE_HEAD_CLASS}
          title={t('Connection limit')}
        >
          {t('Max connections')}
        </TableHead>
        <TableHead className={ROLES_TABLE_HEAD_CLASS} title={t('Valid until')}>
          {t('Expiry')}
        </TableHead>
        <TableHead className={ROLES_TABLE_HEAD_CLASS} title={t('Member of')}>
          {t('Membership')}
        </TableHead>
        <TableHead
          className={`${ROLES_TABLE_HEAD_CLASS} w-[100px] text-end`}
        />
      </TableRow>
    </TableHeader>
  )
}

function RolesSkeletonRows({ rowCount }: { rowCount: number }) {
  return (
    <>
      {Array.from({ length: rowCount }, (_, index) => (
        <TableRow
          key={index}
          className="pointer-events-none hover:bg-transparent"
          aria-hidden
        >
          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3 ps-6 sm:ps-8">
            <Skeleton className="h-3.5 w-24" />
          </TableCell>
          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
            <Skeleton className="h-5 w-10 rounded px-1.5" />
          </TableCell>
          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
            <Skeleton className="h-5 w-10 rounded px-1.5" />
          </TableCell>
          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
            <Skeleton className="h-5 w-10 rounded px-1.5" />
          </TableCell>
          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
            <Skeleton className="h-3.5 w-16" />
          </TableCell>
          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
            <Skeleton className="h-3.5 w-[6.5rem]" />
          </TableCell>
          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
            <Skeleton className="h-3.5 w-full max-w-[12rem]" />
          </TableCell>
          <TableCell className="min-w-0 px-4 py-3 text-end">
            <Skeleton className="ms-auto h-8 w-8 rounded-md" />
          </TableCell>
        </TableRow>
      ))}
    </>
  )
}

function RoleFlagBadge({ enabled }: { enabled: boolean }) {
  const t = useT()
  return (
    <Badge variant={enabled ? 'success' : 'info'} className="text-[10px] shrink-0">
      {enabled ? t('Yes') : t('No')}
    </Badge>
  )
}

function formatConnectionLimitLabel(
  value: number | string | null | undefined,
  t: (text: string) => string,
): string {
  const formatted = formatPostgresRoleConnectionLimit(value)
  if (formatted === null) return t('N/A')
  if (formatted === 'Unlimited') return t('Unlimited')
  return formatted
}

export function PostgresRolesPanel({
  databaseId,
  searchValue,
  createOpen,
  onCreateOpenChange,
}: PostgresRolesPanelProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const { canWrite } = useDatabaseTableOperationsAccess()

  const { roles, isLoading, refetch } = usePostgresRoles(projectId, databaseId)
  const executeSql = useExecutePostgresSql(projectId, databaseId)

  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [roleToDelete, setRoleToDelete] = useState<PostgresRoleRow | null>(null)
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [selectedRole, setSelectedRole] = useState<PostgresRoleRow | null>(null)

  useEffect(() => {
    if (!createOpen) return
    setSelectedRole(null)
    setDrawerOpen(true)
  }, [createOpen])

  const handleDrawerOpenChange = (open: boolean) => {
    setDrawerOpen(open)
    if (!open) {
      setSelectedRole(null)
      onCreateOpenChange(false)
    }
  }

  const filteredRoles = useMemo(() => {
    if (!searchValue.trim()) return roles
    return roles.filter((role) =>
      matchesPostgresLocalSearch(searchValue, role.role_name),
    )
  }, [roles, searchValue])

  const handleDelete = async () => {
    if (!roleToDelete) return
    const role = roleToDelete
    closeDialogBeforeOverlayUnmount(() => {
      setDeleteDialogOpen(false)
      setRoleToDelete(null)
    })

    try {
      await executeSql.mutateAsync(buildPostgresDropRoleSql(role.role_name))
      toast.success(t('Role deleted'))
      await refetch()
    } catch (error) {
      toast.error(getErrorMessage(error) ?? t('Failed to delete role'))
    }
  }

  if (!isLoading && roles.length === 0) {
    return (
      <>
        <div className="flex min-h-0 flex-1 w-full items-center justify-center px-4 py-8">
          <div className="w-full max-w-sm">
            <EmptyState
              variant="centered"
              icon={Users}
              iconSize="md"
              title={t('No roles yet')}
              description={t(
                'Create roles to reference in RLS policies and control database access.',
              )}
              isEmpty
              className="w-full"
              action={
                canWrite ? (
                  <Button size="sm" onClick={() => onCreateOpenChange(true)}>
                    <Plus className="me-1.5 h-3.5 w-3.5" />
                    {t('Create role')}
                  </Button>
                ) : undefined
              }
            />
          </div>
        </div>
        <PostgresRoleDrawer
          open={drawerOpen}
          onOpenChange={handleDrawerOpenChange}
          projectId={projectId}
          databaseId={databaseId}
          role={selectedRole}
          availableRoles={roles}
          onSuccess={() => void refetch()}
        />
      </>
    )
  }

  return (
    <>
      <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
        {isLoading && roles.length === 0 ? (
          <div
            className="relative min-h-0 min-w-0 flex-1 overflow-auto"
            role="status"
            aria-live="polite"
            aria-busy="true"
            aria-label={t('Loading roles…')}
          >
            <div className={rolesTableScrollWrapperClassName}>
              <Table withScrollContainer={false} className={rolesTableClassName}>
                <RolesTableColGroup />
                <RolesTableHead />
                <TableBody>
                  <RolesSkeletonRows rowCount={8} />
                </TableBody>
              </Table>
            </div>
          </div>
        ) : filteredRoles.length > 0 ? (
          <>
            <div className="relative min-h-0 min-w-0 flex-1 overflow-auto">
              <div className={rolesTableScrollWrapperClassName}>
                <Table withScrollContainer={false} className={rolesTableClassName}>
                  <RolesTableColGroup />
                  <RolesTableHead />
                  <TableBody>
                    {filteredRoles.map((role) => {
                      const protectedRole = isPostgresProtectedRole(role)
                      const canDelete = canWrite && !protectedRole
                      const canUpdate = canWrite && canUpdatePostgresRole(role)
                      const membershipLabel = formatPostgresRoleMembership(
                        role.member_of,
                      )

                      return (
                        <TableRow key={role.role_name}>
                        <TableCell className="min-w-0 px-4 py-3 ps-6 sm:ps-8">
                          <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                            <span className="text-[13px] font-medium text-foreground">
                              {role.role_name}
                            </span>
                            {isPostgresBuiltinRole(role) ? (
                              <Badge
                                variant="info"
                                className="shrink-0 text-[10px]"
                              >
                                {t('System')}
                              </Badge>
                            ) : null}
                            {isPostgresRoleFlag(role.is_superuser) ? (
                              <Badge
                                variant="warning"
                                className="shrink-0 text-[10px]"
                              >
                                {t('Superuser')}
                              </Badge>
                            ) : null}
                          </div>
                        </TableCell>
                        <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
                          <RoleFlagBadge
                            enabled={isPostgresRoleFlag(role.can_login)}
                          />
                        </TableCell>
                        <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
                          <RoleFlagBadge
                            enabled={isPostgresRoleFlag(role.can_create_role)}
                          />
                        </TableCell>
                        <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
                          <RoleFlagBadge
                            enabled={isPostgresRoleFlag(role.can_create_db)}
                          />
                        </TableCell>
                        <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
                          <span className="text-[13px] text-muted-foreground">
                            {formatConnectionLimitLabel(role.connection_limit, t)}
                          </span>
                        </TableCell>
                        <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
                          {role.valid_until ? (
                            <DateTooltip
                              date={role.valid_until}
                              className="text-[13px] text-muted-foreground"
                            />
                          ) : (
                            <span className="text-[13px] text-muted-foreground">
                              {t('N/A')}
                            </span>
                          )}
                        </TableCell>
                        <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
                          {membershipLabel ? (
                            <span
                              className="block truncate text-[13px] text-muted-foreground"
                              title={membershipLabel}
                            >
                              {membershipLabel}
                            </span>
                          ) : (
                            <span className="text-[13px] text-muted-foreground">
                              {t('N/A')}
                            </span>
                          )}
                        </TableCell>
                        <TableCell className="px-4 py-3 text-end">
                          <div className="flex justify-end">
                            {canUpdate || canDelete ? (
                              <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                  <RowActionsMenuTrigger />
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                  {canUpdate ? (
                                    <DropdownMenuItem
                                      onSelect={() => {
                                        openDialogAfterOverlayCloses(() => {
                                          setSelectedRole(role)
                                          setDrawerOpen(true)
                                        })
                                      }}
                                    >
                                      <MenuItemContent icon={Pencil}>
                                        {t('Update')}
                                      </MenuItemContent>
                                    </DropdownMenuItem>
                                  ) : null}
                                  {canDelete ? (
                                    <DropdownMenuItem
                                      onSelect={() => {
                                        openDialogAfterOverlayCloses(() => {
                                          setRoleToDelete(role)
                                          setDeleteDialogOpen(true)
                                        })
                                      }}
                                    >
                                      <MenuItemContent icon={Trash2}>
                                        {t('Delete')}
                                      </MenuItemContent>
                                    </DropdownMenuItem>
                                  ) : null}
                                </DropdownMenuContent>
                              </DropdownMenu>
                            ) : null}
                          </div>
                        </TableCell>
                      </TableRow>
                    )
                  })}
                  </TableBody>
                </Table>
              </div>
            </div>
            <div className="h-[54px] shrink-0 border-t border-border bg-background px-4 sm:px-6">
              <div className="flex h-full items-center justify-between gap-3 py-3">
                <p className="text-[13px] text-muted-foreground">
                  {filteredRoles.length === roles.length
                    ? `${roles.length} role${roles.length === 1 ? '' : 's'}`
                    : `${filteredRoles.length} of ${roles.length} roles`}
                </p>
              </div>
            </div>
          </>
        ) : (
          <EmptyState
            icon={Users}
            title={t('No roles match your search')}
            description={t('Try adjusting or clearing your search.')}
            hasFilters
            variant="centered"
          />
        )}
      </div>

      <PostgresRoleDrawer
        open={drawerOpen}
        onOpenChange={handleDrawerOpenChange}
        projectId={projectId}
        databaseId={databaseId}
        role={selectedRole}
        availableRoles={roles}
        onSuccess={() => void refetch()}
      />

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>{t('Delete role')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Delete')} &quot;{roleToDelete?.role_name}&quot;?{' '}
              {t('This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={executeSql.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={() => void handleDelete()}
              disabled={executeSql.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
