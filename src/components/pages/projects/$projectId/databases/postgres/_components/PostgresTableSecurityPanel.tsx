import { useMemo, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
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
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { RefreshButton } from '@/components/global/shared/RefreshButton'
import { useDatabaseTableOperationsAccess } from '../../_components/DatabaseOperationsLockContext'
import {
  useExecutePostgresSql,
  usePostgresTableInfo,
  usePostgresTablePolicies,
  usePostgresTableRls,
} from '@/lib/react-query/hooks'
import { buildPostgresSingleRequestDdlSql } from '@/lib/postgres-sql'
import {
  buildPostgresDisableRlsSql,
  buildPostgresDropPolicySql,
  buildPostgresEnableRlsSql,
  buildPostgresForceRlsSql,
  buildPostgresNoForceRlsSql,
  formatPostgresPolicyRoles,
  normalizePostgresPolicyCommand,
  type PostgresTablePolicyRow,
} from '@/lib/postgres-rls'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { Pencil, Plus, Search, Shield, Trash2 } from 'lucide-react'
import { PostgresTablePolicyDrawer } from './PostgresTablePolicyDrawer'
import { useT } from '@/lib/i18n/translate'
import {
  matchesPostgresLocalSearch,
} from './postgres-spreadsheet-chrome'

type PostgresTableSecurityPanelProps = {
  databaseId: string
  tableId: string
}

function isBaseTable(tableType: string | null | undefined): boolean {
  return tableType?.toUpperCase() === 'BASE TABLE'
}

export function PostgresTableSecurityPanel({
  databaseId,
  tableId,
}: PostgresTableSecurityPanelProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const { canWrite } = useDatabaseTableOperationsAccess()

  const { tableInfo, isLoading: tableInfoLoading } = usePostgresTableInfo(
    projectId,
    databaseId,
    tableId,
  )
  const {
    rowSecurityEnabled,
    forceRowSecurity,
    isLoading: rlsLoading,
    isFetching: rlsFetching,
    refetch: refetchRls,
  } = usePostgresTableRls(projectId, databaseId, tableId)
  const {
    policies,
    isLoading: policiesLoading,
    isFetching: policiesFetching,
    refetch: refetchPolicies,
  } = usePostgresTablePolicies(projectId, databaseId, tableId)
  const executeSql = useExecutePostgresSql(projectId, databaseId)

  const [searchValue, setSearchValue] = useState('')
  const [dialogOpen, setDialogOpen] = useState(false)
  const [selectedPolicy, setSelectedPolicy] =
    useState<PostgresTablePolicyRow | null>(null)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [policyToDelete, setPolicyToDelete] = useState<string | null>(null)
  const [rowSecurityDraft, setRowSecurityDraft] = useState<boolean | null>(null)
  const [forceRowSecurityDraft, setForceRowSecurityDraft] = useState<
    boolean | null
  >(null)

  const supportsRls = isBaseTable(tableInfo?.table_type)
  const effectiveRowSecurity = rowSecurityDraft ?? rowSecurityEnabled
  const effectiveForceRowSecurity = forceRowSecurityDraft ?? forceRowSecurity
  const isLoading = tableInfoLoading || rlsLoading || policiesLoading

  const setDialogOpenState = (open: boolean) => {
    setDialogOpen(open)
    if (!open) setSelectedPolicy(null)
  }

  const handleOpenCreate = () => {
    setSelectedPolicy(null)
    setDialogOpenState(true)
  }

  const handleOpenEdit = (policy: PostgresTablePolicyRow) => {
    setSelectedPolicy(policy)
    setDialogOpen(true)
  }

  const refreshSecurity = async () => {
    setRowSecurityDraft(null)
    setForceRowSecurityDraft(null)
    await Promise.all([refetchRls(), refetchPolicies()])
  }

  const handleRefreshPolicies = () => {
    void refreshSecurity()
  }

  const handleUpdateRls = async () => {
    try {
      const statements: string[] = []
      if (effectiveRowSecurity !== rowSecurityEnabled) {
        statements.push(
          effectiveRowSecurity
            ? buildPostgresEnableRlsSql(tableId)
            : buildPostgresDisableRlsSql(tableId),
        )
      }
      if (effectiveForceRowSecurity !== forceRowSecurity) {
        statements.push(
          effectiveForceRowSecurity
            ? buildPostgresForceRlsSql(tableId)
            : buildPostgresNoForceRlsSql(tableId),
        )
      }
      if (statements.length === 0) return

      await executeSql.mutateAsync(
        buildPostgresSingleRequestDdlSql(statements, 'Update table RLS'),
      )
      toast.success(t('Security has been updated'))
      await refreshSecurity()
    } catch (error) {
      toast.error(getErrorMessage(error) ?? t('Failed to update security'))
    }
  }

  const handleDeletePolicy = async () => {
    if (!policyToDelete) return
    try {
      await executeSql.mutateAsync(
        buildPostgresDropPolicySql(tableId, policyToDelete),
      )
      toast.success(t('Policy deleted'))
      setDeleteDialogOpen(false)
      setPolicyToDelete(null)
      await refetchPolicies()
    } catch (error) {
      toast.error(getErrorMessage(error) ?? t('Failed to delete policy'))
    }
  }

  const filteredPolicies = useMemo(() => {
    return policies.filter((policy) =>
      matchesPostgresLocalSearch(
        searchValue,
        policy.policyname,
        policy.cmd,
        policy.permissive,
        formatPostgresPolicyRoles(policy.roles),
        policy.qual ?? '',
        policy.with_check ?? '',
      ),
    )
  }, [policies, searchValue])

  const hasSearch = searchValue.trim().length > 0
  const isRefreshingPolicies = policiesFetching || rlsFetching
  const rlsChanged =
    effectiveRowSecurity !== rowSecurityEnabled ||
    effectiveForceRowSecurity !== forceRowSecurity

  if (isLoading && !tableInfo) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-[13px] text-muted-foreground">
          {t('Loading security settings…')}
        </div>
      </div>
    )
  }

  if (!supportsRls) {
    return (
      <div className="flex h-full flex-col items-center justify-center px-6 py-12 text-center">
        <p className="text-[15px] font-medium text-foreground">
          {t('Row level security is not available')}
        </p>
        <p className="mt-2 max-w-sm text-[13px] text-muted-foreground">
          {t('Row level security can only be configured on base tables.')}
        </p>
      </div>
    )
  }

  return (
    <>
      <div className="w-full flex-1 overflow-y-auto px-4 py-4 sm:px-6">
        <div className="mx-auto w-full max-w-7xl space-y-6">
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Row level security (RLS)')}
              </h3>
              <p className="text-[13px] text-muted-foreground mt-2">
                {t(
                  'When row level security is enabled, access to rows is controlled by policies on this table.',
                )}
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4 space-y-4">
              <div className="flex items-center gap-3">
                <Switch
                  id="postgres-row-security"
                  checked={effectiveRowSecurity}
                  onCheckedChange={(checked) => setRowSecurityDraft(checked)}
                  disabled={!canWrite}
                />
                <Label
                  htmlFor="postgres-row-security"
                  className="text-[13px] text-foreground"
                >
                  {t('Enable row level security')}
                </Label>
              </div>
              <div className="flex items-center gap-3">
                <Switch
                  id="postgres-force-row-security"
                  checked={effectiveForceRowSecurity}
                  onCheckedChange={(checked) => setForceRowSecurityDraft(checked)}
                  disabled={!canWrite || !effectiveRowSecurity}
                />
                <Label
                  htmlFor="postgres-force-row-security"
                  className="text-[13px] text-foreground"
                >
                  {t('Force row level security for table owner')}
                </Label>
              </div>
              <p className="text-[13px] text-muted-foreground">
                {t(
                  'Policies define which roles can read or write rows. Without a matching policy, access is denied when row level security is enabled.',
                )}
              </p>
            </div>
            {canWrite ? (
              <div className="px-6 py-4 border-t border-border bg-muted/30">
                <Button
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled={!rlsChanged || executeSql.isPending}
                  onClick={() => void handleUpdateRls()}
                >
                  {t('Update')}
                </Button>
              </div>
            ) : null}
          </div>

          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                  <h3 className="text-[15px] font-semibold text-foreground">
                    {t('Policies')}
                  </h3>
                  <p className="text-[13px] text-muted-foreground mt-2">
                    {t('Manage row level security policies for this table.')}
                  </p>
                </div>
                {policies.length > 0 ? (
                  <div className="flex w-full flex-wrap items-center gap-2 lg:w-auto lg:justify-end">
                    <div className="relative min-w-[200px] flex-1 lg:w-[240px] lg:flex-none">
                      <Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                      <Input
                        type="text"
                        value={searchValue}
                        onChange={(event) => setSearchValue(event.target.value)}
                        placeholder={t('Search policies...')}
                        className="h-9 ps-9 text-[13px]"
                        aria-label={t('Search policies...')}
                      />
                    </div>
                    <RefreshButton
                      onClick={handleRefreshPolicies}
                      isRefreshing={isRefreshingPolicies}
                    />
                    {canWrite ? (
                      <Button
                        size="sm"
                        className="h-9 text-[13px]"
                        onClick={handleOpenCreate}
                      >
                        <Plus className="me-1.5 h-4 w-4" />
                        {t('Create policy')}
                      </Button>
                    ) : null}
                  </div>
                ) : null}
              </div>
            </div>
            <div className="border-t border-border" />
            {policies.length === 0 ? (
              <div className="px-6 py-8 min-h-[280px] flex flex-col items-center justify-center">
                <EmptyState
                  icon={Shield}
                  title={t('No policies')}
                  description={
                    canWrite
                      ? t(
                          'Create a policy to control access to rows in this table.',
                        )
                      : t('This table has no row level security policies yet.')
                  }
                  isEmpty
                  iconSize="lg"
                  action={
                    canWrite ? (
                      <Button size="sm" className="h-9 text-[13px]" onClick={handleOpenCreate}>
                        {t('Create policy')}
                      </Button>
                    ) : undefined
                  }
                />
              </div>
            ) : hasSearch && filteredPolicies.length === 0 ? (
              <div className="px-6 py-8 min-h-[200px] flex flex-col items-center justify-center">
                <EmptyState
                  icon={Shield}
                  title={t('No policies match your search')}
                  description={t('Try adjusting or clearing your search.')}
                  hasFilters
                  iconSize="lg"
                />
              </div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Policy Name')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Command')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Roles')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Type')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[100px]" />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {filteredPolicies.map((policy) => (
                    <TableRow key={policy.policyname}>
                      <TableCell className="px-4 py-3">
                        <span className="text-[13px] font-medium">
                          {policy.policyname}
                        </span>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <Badge variant="info" className="text-[10px] shrink-0">
                          {normalizePostgresPolicyCommand(policy.cmd)}
                        </Badge>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <span className="text-[13px] text-muted-foreground">
                          {formatPostgresPolicyRoles(policy.roles)}
                        </span>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <span className="text-[13px] text-muted-foreground">
                          {policy.permissive?.toUpperCase() === 'RESTRICTIVE'
                            ? t('Restrictive')
                            : t('Permissive')}
                        </span>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-right">
                        {canWrite ? (
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <RowActionsMenuTrigger />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                              <DropdownMenuItem
                                onClick={() => handleOpenEdit(policy)}
                              >
                                <MenuItemContent icon={Pencil}>
                                  {t('Update')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                onClick={() => {
                                  setPolicyToDelete(policy.policyname)
                                  setDeleteDialogOpen(true)
                                }}
                              >
                                <MenuItemContent icon={Trash2}>
                                  {t('Delete')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                            </DropdownMenuContent>
                          </DropdownMenu>
                        ) : null}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </div>
        </div>
      </div>

      <PostgresTablePolicyDrawer
        key={selectedPolicy?.policyname ?? 'create'}
        open={dialogOpen}
        onOpenChange={setDialogOpenState}
        projectId={projectId}
        databaseId={databaseId}
        tableId={tableId}
        policy={selectedPolicy}
        onSuccess={() => void refetchPolicies()}
      />

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>{t('Delete policy')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Delete')} &quot;{policyToDelete}&quot;?{' '}
              {t('This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              disabled={executeSql.isPending}
              onClick={() => void handleDeletePolicy()}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
