import { useCallback, useMemo, useState } from 'react'
import {
  AlertCircle,
  Cable,
  StopCircle,
  Unplug,
} from 'lucide-react'
import { RefreshButton } from '@/components/global/shared/RefreshButton'
import {
  useCancelPostgresBackend,
  usePostgresActiveConnections,
  useTerminatePostgresBackend,
  useTerminatePostgresIdleInTransaction,
} from '@/lib/react-query/hooks'
import { useDatabaseAdminOperationsAccess } from '../_components/DatabaseOperationsLockContext'
import {
  backendTypeBadgeVariant,
  connectionStateBadgeVariant,
  formatPostgresApplicationName,
  formatPostgresClientAddress,
  formatPostgresConnectionDatabase,
  formatPostgresConnectionUsername,
  formatPostgresDurationSince,
  formatPostgresWaitEvent,
  isLongRunningConnection,
  isPostgresClientBackend,
  matchesPostgresConnectionBackendScope,
  matchesPostgresConnectionStateFilter,
  type PostgresActiveConnectionRow,
  type PostgresConnectionBackendScope,
  type PostgresConnectionStateFilter,
} from '@/lib/postgres-metrics'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
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
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { usePostgresSidebar } from './_components/PostgresSidebarContext'
import { PostgresConnectionDrawer } from './_components/PostgresConnectionDrawer'
import { PostgresConnectionContextMenu } from './_components/PostgresConnectionContextMenu'
import { PostgresConnectionRowActionsMenu } from './_components/PostgresConnectionRowActionsMenu'
import { PostgresSegmentedToggle } from './_components/PostgresSegmentedToggle'
import { useT } from '@/lib/i18n/translate'
import {
  localizePostgresBackendTypeLabel,
  localizePostgresConnectionStateLabel,
} from '@/lib/i18n/resource-status-labels'

type PostgresConnectionDetailsProps = {
  projectId: string
  databaseId: string
  centerInPanel?: boolean
}

type PendingConnectionAction =
  | { type: 'cancel'; connection: PostgresActiveConnectionRow }
  | { type: 'terminate'; connection: PostgresActiveConnectionRow }
  | { type: 'terminate-idle' }

const BACKEND_SCOPES: Array<{
  id: PostgresConnectionBackendScope
  label: string
}> = [
  { id: 'clients', label: 'Clients' },
  { id: 'backends', label: 'Backends' },
]

const STATE_FILTERS: Array<{
  id: PostgresConnectionStateFilter
  label: string
}> = [
  { id: 'all', label: 'All' },
  { id: 'active', label: 'Active' },
  { id: 'idle', label: 'Idle' },
  { id: 'idle in transaction', label: 'Idle in transaction' },
  { id: 'long-running', label: 'Long-running' },
]

function truncateQuery(query: string | null, maxLength = 72): string {
  if (!query) return '-'
  const trimmed = query.replace(/\s+/g, ' ').trim()
  if (trimmed.length <= maxLength) return trimmed
  return `${trimmed.slice(0, maxLength)}…`
}

const connectionsTableClassName = 'w-full min-w-[80rem] table-fixed'

function ConnectionsTableColGroup() {
  return (
    <colgroup>
      <col className="w-[7rem]" />
      <col className="w-[7%]" />
      <col className="w-[8%]" />
      <col className="w-[9%]" />
      <col className="w-[9%]" />
      <col className="w-[10%]" />
      <col className="w-[8%]" />
      <col className="w-[7%]" />
      <col className="w-[9%]" />
      <col className="" />
      <col className="w-[10%]" />
      <col className="w-[100px]" />
    </colgroup>
  )
}

function ConnectionsTableHead() {
  const t = useT()
  return (
    <TableHeader>
      <TableRow className="border-b border-border hover:bg-transparent">
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 ps-6 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)] sm:ps-8">
          PID
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          {t('Type')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          {t('User')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          {t('Database')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          {t('Application')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          {t('Client')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          {t('Connection state')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          {t('Duration')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          {t('Wait')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          {t('Query')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          {t('Started')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 w-[100px] bg-background px-4 py-3 text-end text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]" />
      </TableRow>
    </TableHeader>
  )
}

function ConnectionsSkeletonRows({ rowCount }: { rowCount: number }) {
  return (
    <>
      {Array.from({ length: rowCount }, (_, index) => (
        <TableRow
          key={index}
          className="pointer-events-none hover:bg-transparent"
          aria-hidden
        >
          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3 ps-6 sm:ps-8">
            <Skeleton className="h-3.5 w-10" />
          </TableCell>
          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
            <Skeleton className="h-5 w-14 rounded px-1.5" />
          </TableCell>
          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
            <Skeleton className="h-3.5 w-16" />
          </TableCell>
          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
            <Skeleton className="h-3.5 w-20" />
          </TableCell>
          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
            <Skeleton className="h-3.5 w-16" />
          </TableCell>
          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
            <Skeleton className="h-3.5 w-24" />
          </TableCell>
          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
            <Skeleton className="h-5 w-14 rounded px-1.5" />
          </TableCell>
          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
            <Skeleton className="h-3.5 w-10" />
          </TableCell>
          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
            <Skeleton className="h-3.5 w-16" />
          </TableCell>
          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
            <Skeleton className="h-3.5 w-full max-w-[12rem]" />
          </TableCell>
          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
            <Skeleton className="h-3.5 w-[6.5rem]" />
          </TableCell>
          <TableCell className="min-w-0 px-4 py-3 text-end">
            <Skeleton className="ms-auto h-8 w-8 rounded-md" />
          </TableCell>
        </TableRow>
      ))}
    </>
  )
}

export function PostgresConnectionDetails({
  projectId,
  databaseId,
  centerInPanel = false,
}: PostgresConnectionDetailsProps) {
  const t = useT()
  const { openQueryTab } = usePostgresSidebar()
  const {
    canWrite: canManageConnections,
    writeTooltip: manageDisabledTooltip,
  } = useDatabaseAdminOperationsAccess({
    permissionDeniedTooltip: t(
      "You don't have permission to manage connections.",
    ),
  })

  const [backendScope, setBackendScope] =
    useState<PostgresConnectionBackendScope>('clients')
  const [stateFilter, setStateFilter] =
    useState<PostgresConnectionStateFilter>('all')
  const [selectedConnection, setSelectedConnection] =
    useState<PostgresActiveConnectionRow | null>(null)
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [pendingAction, setPendingAction] =
    useState<PendingConnectionAction | null>(null)

  const {
    connections,
    isLoading,
    isFetching,
    error,
    refetch,
  } = usePostgresActiveConnections(projectId, databaseId)

  const cancelMutation = useCancelPostgresBackend(projectId, databaseId)
  const terminateMutation = useTerminatePostgresBackend(projectId, databaseId)
  const terminateIdleMutation = useTerminatePostgresIdleInTransaction(
    projectId,
    databaseId,
  )

  const scopedConnections = useMemo(
    () =>
      connections.filter((connection) =>
        matchesPostgresConnectionBackendScope(connection, backendScope),
      ),
    [connections, backendScope],
  )

  const filteredConnections = useMemo(
    () =>
      scopedConnections.filter((connection) =>
        matchesPostgresConnectionStateFilter(connection, stateFilter),
      ),
    [scopedConnections, stateFilter],
  )

  const clientConnectionCount = useMemo(
    () => connections.filter(isPostgresClientBackend).length,
    [connections],
  )

  const backendConnectionCount = useMemo(
    () => connections.filter((connection) => !isPostgresClientBackend(connection)).length,
    [connections],
  )

  const idleInTransactionCount = useMemo(
    () =>
      connections.filter(
        (connection) =>
          isPostgresClientBackend(connection) &&
          matchesPostgresConnectionStateFilter(
            connection,
            'idle in transaction',
          ),
      ).length,
    [connections],
  )

  const filterCounts = useMemo(() => {
    const counts: Record<PostgresConnectionStateFilter, number> = {
      all: scopedConnections.length,
      active: 0,
      idle: 0,
      'idle in transaction': 0,
      'long-running': 0,
    }
    for (const connection of scopedConnections) {
      if (connection.state?.toLowerCase() === 'active') counts.active += 1
      if (connection.state?.toLowerCase() === 'idle') counts.idle += 1
      if (
        matchesPostgresConnectionStateFilter(connection, 'idle in transaction')
      ) {
        counts['idle in transaction'] += 1
      }
      if (isLongRunningConnection(connection)) counts['long-running'] += 1
    }
    return counts
  }, [scopedConnections])

  const errorMessage = error ? getErrorMessage(error) : null
  const actionPending =
    cancelMutation.isPending ||
    terminateMutation.isPending ||
    terminateIdleMutation.isPending

  const openDrawer = useCallback((connection: PostgresActiveConnectionRow) => {
    setSelectedConnection(connection)
    setDrawerOpen(true)
  }, [])

  const closeDrawer = useCallback(() => {
    setDrawerOpen(false)
    setSelectedConnection(null)
  }, [])

  const openInSqlEditor = useCallback(
    (sql: string) => {
      openQueryTab(sql)
    },
    [openQueryTab],
  )

  const runPendingAction = useCallback(async () => {
    if (!pendingAction) return

    try {
      if (pendingAction.type === 'cancel') {
        await cancelMutation.mutateAsync(pendingAction.connection.pid)
      } else if (pendingAction.type === 'terminate') {
        await terminateMutation.mutateAsync(pendingAction.connection.pid)
      } else {
        await terminateIdleMutation.mutateAsync()
      }
      if (
        pendingAction.type !== 'terminate-idle' &&
        selectedConnection?.pid === pendingAction.connection.pid
      ) {
        closeDrawer()
      }
    } finally {
      setPendingAction(null)
    }
  }, [
    cancelMutation,
    closeDrawer,
    pendingAction,
    selectedConnection?.pid,
    terminateIdleMutation,
    terminateMutation,
  ])

  const bulkTerminateButton = (
    <Button
      type="button"
      variant="outline"
      size="sm"
      className="h-9 shrink-0 text-[13px]"
      disabled={
        !canManageConnections ||
        idleInTransactionCount === 0 ||
        actionPending
      }
      onClick={() => setPendingAction({ type: 'terminate-idle' })}
    >
      <Unplug className="me-1.5 h-3.5 w-3.5" />
      {t('Terminate idle in transaction')}
    </Button>
  )

  return (
    <div
      className={cn(
        'flex min-h-0 flex-1 flex-col overflow-hidden',
        centerInPanel && 'items-center justify-center',
      )}
    >
      <div
        className={cn(
          'flex min-h-0 w-full flex-1 flex-col overflow-hidden',
          centerInPanel && 'my-auto max-h-full shrink-0',
        )}
      >
        {errorMessage ? (
          <div className="shrink-0 px-4 pb-4 pt-4 sm:px-6">
            <Alert variant="destructive">
              <AlertCircle className="h-4 w-4" />
              <AlertTitle>{t('Failed to load connections')}</AlertTitle>
              <AlertDescription className="text-[13px]">
                {errorMessage}
              </AlertDescription>
            </Alert>
          </div>
        ) : null}

        <div className="shrink-0 border-b border-border bg-background px-4 py-3 sm:px-6">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex flex-wrap items-center gap-2">
              <PostgresSegmentedToggle
                variant="inline"
                value={backendScope}
                onValueChange={(value) => {
                  setBackendScope(value)
                  setStateFilter('all')
                }}
                ariaLabel="Connection scope"
                options={BACKEND_SCOPES.map((scope) => ({
                  value: scope.id,
                  label: scope.label,
                }))}
              />
              {backendScope === 'clients' ? (
                <>
                  <div
                    className="hidden h-4 w-px shrink-0 bg-border sm:block"
                    aria-hidden
                  />
                  {STATE_FILTERS.map((filter) => {
                    const count = filterCounts[filter.id]
                    const active = stateFilter === filter.id
                    return (
                      <Button
                        key={filter.id}
                        type="button"
                        variant={active ? 'secondary' : 'outline'}
                        size="sm"
                        className="h-8 text-[12px]"
                        onClick={() => setStateFilter(filter.id)}
                      >
                        {filter.label}
                        <span className="ms-1.5 text-muted-foreground">
                          {count}
                        </span>
                      </Button>
                    )
                  })}
                </>
              ) : null}
            </div>
            <div className="flex flex-wrap items-center gap-2">
              {!canManageConnections ? (
                <TooltipProvider delayDuration={0}>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <span className="inline-flex">{bulkTerminateButton}</span>
                    </TooltipTrigger>
                    <TooltipContent side="bottom" className="max-w-xs">
                      <p className="text-[12px]">{t(manageDisabledTooltip)}</p>
                    </TooltipContent>
                  </Tooltip>
                </TooltipProvider>
              ) : (
                bulkTerminateButton
              )}
              <RefreshButton
                onClick={() => void refetch()}
                isRefreshing={isFetching}
              />
            </div>
          </div>
        </div>

        <div className="flex min-h-0 min-w-0 flex-1 flex-col">
          {isLoading && connections.length === 0 ? (
            <div
              className="relative min-h-0 min-w-0 flex-1 overflow-auto"
              role="status"
              aria-live="polite"
              aria-busy="true"
              aria-label={t('Loading connections')}
            >
              <Table withScrollContainer={false} className={connectionsTableClassName}>
                <ConnectionsTableColGroup />
                <ConnectionsTableHead />
                <TableBody>
                  <ConnectionsSkeletonRows rowCount={8} />
                </TableBody>
              </Table>
            </div>
          ) : filteredConnections.length > 0 ? (
            <>
              <div className="relative min-h-0 min-w-0 flex-1 overflow-auto">
                <Table withScrollContainer={false} className={connectionsTableClassName}>
                  <ConnectionsTableColGroup />
                  <ConnectionsTableHead />
                  <TableBody>
                    {filteredConnections.map((connection) => {
                      const longRunning = isLongRunningConnection(connection)
                      const clientAddress = formatPostgresClientAddress(
                        connection.clientHost,
                        connection.clientPort,
                      )
                      const startedAt =
                        connection.queryStart ?? connection.backendStart
                      const usernameLabel = formatPostgresConnectionUsername(
                        connection.username,
                        connection.backendType,
                      )
                      const databaseLabel = formatPostgresConnectionDatabase(
                        connection.database,
                      )
                      const applicationLabel = formatPostgresApplicationName(
                        connection.applicationName,
                      )
                      const stateLabel = localizePostgresConnectionStateLabel(
                        connection.state,
                        connection.backendType,
                        t,
                      )
                      const typeLabel = localizePostgresBackendTypeLabel(
                        connection.backendType,
                        t,
                      )

                      return (
                        <PostgresConnectionContextMenu
                          key={connection.pid}
                          connection={connection}
                          canManageConnections={canManageConnections}
                          onOpenDetails={() => openDrawer(connection)}
                          onOpenInSqlEditor={openInSqlEditor}
                          onCancelQuery={() =>
                            setPendingAction({
                              type: 'cancel',
                              connection,
                            })
                          }
                          onTerminateConnection={() =>
                            setPendingAction({
                              type: 'terminate',
                              connection,
                            })
                          }
                        >
                          <TableRow
                            role="button"
                            tabIndex={0}
                            data-state={
                              drawerOpen &&
                              selectedConnection?.pid === connection.pid
                                ? 'selected'
                                : undefined
                            }
                            aria-label={`Open connection details for PID ${connection.pid}`}
                            className={cn(
                              'cursor-pointer',
                              longRunning && 'bg-amber-500/5 hover:bg-amber-500/10',
                              drawerOpen &&
                                selectedConnection?.pid === connection.pid &&
                                'bg-muted/60 hover:bg-muted/60',
                            )}
                            onClick={() => openDrawer(connection)}
                            onKeyDown={(event) => {
                              if (event.key === 'Enter' || event.key === ' ') {
                                event.preventDefault()
                                openDrawer(connection)
                              }
                            }}
                          >
                          <TableCell className="overflow-hidden whitespace-nowrap px-4 py-3 ps-6 sm:ps-8">
                            <span className="block truncate font-mono text-[13px] text-foreground">
                              {connection.pid}
                            </span>
                          </TableCell>
                          <TableCell className="overflow-hidden whitespace-nowrap px-4 py-3">
                            <Badge
                              variant={backendTypeBadgeVariant(
                                connection.backendType,
                              )}
                              className="shrink-0 text-[10px]"
                            >
                              {typeLabel}
                            </Badge>
                          </TableCell>
                          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
                            <span
                              className={cn(
                                'block truncate text-[13px]',
                                usernameLabel === 'System'
                                  ? 'text-muted-foreground'
                                  : 'font-medium text-foreground',
                              )}
                              title={usernameLabel}
                            >
                              {usernameLabel}
                            </span>
                          </TableCell>
                          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
                            <span
                              className={cn(
                                'block truncate font-mono text-[13px]',
                                databaseLabel === '-'
                                  ? 'text-muted-foreground'
                                  : 'text-foreground',
                              )}
                              title={databaseLabel}
                            >
                              {databaseLabel}
                            </span>
                          </TableCell>
                          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
                            <span
                              className="block truncate text-[13px] text-muted-foreground"
                              title={applicationLabel}
                            >
                              {applicationLabel}
                            </span>
                          </TableCell>
                          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
                            <span
                              className="block truncate font-mono text-[13px] text-muted-foreground"
                              title={clientAddress}
                            >
                              {clientAddress}
                            </span>
                          </TableCell>
                          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
                            <div className="flex items-center gap-1.5">
                              {stateLabel === '-' ? (
                                <span className="text-[13px] text-muted-foreground">
                                  -
                                </span>
                              ) : (
                                <Badge
                                  variant={connectionStateBadgeVariant(
                                    connection.state,
                                    connection.backendType,
                                  )}
                                  className="shrink-0 text-[10px]"
                                >
                                  {stateLabel}
                                </Badge>
                              )}
                              {longRunning ? (
                                <Badge
                                  variant="warning"
                                  className="shrink-0 text-[10px]"
                                >
                                  {t('Long-running')}
                                </Badge>
                              ) : null}
                            </div>
                          </TableCell>
                          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
                            <span className="text-[13px] text-muted-foreground">
                              {formatPostgresDurationSince(
                                connection.queryStart ??
                                  connection.backendStart,
                              )}
                            </span>
                          </TableCell>
                          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
                            <span
                              className="block truncate text-[13px] text-muted-foreground"
                              title={formatPostgresWaitEvent(
                                connection.waitEventType,
                                connection.waitEvent,
                              )}
                            >
                              {formatPostgresWaitEvent(
                                connection.waitEventType,
                                connection.waitEvent,
                              )}
                            </span>
                          </TableCell>
                          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
                            <span
                              className="block truncate font-mono text-[12px] text-muted-foreground"
                              title={connection.query ?? undefined}
                            >
                              {truncateQuery(connection.query)}
                            </span>
                          </TableCell>
                          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
                            {startedAt ? (
                              <DateTooltip
                                date={startedAt}
                                className="text-[12px] text-muted-foreground"
                              />
                            ) : (
                              <span className="text-[12px] text-muted-foreground">
                                -
                              </span>
                            )}
                          </TableCell>
                          <TableCell
                            className="px-4 py-3 text-end"
                            onClick={(event) => event.stopPropagation()}
                          >
                            <div className="flex justify-end">
                              <PostgresConnectionRowActionsMenu
                                connection={connection}
                                canManageConnections={canManageConnections}
                                onOpenDetails={() => openDrawer(connection)}
                                onOpenInSqlEditor={openInSqlEditor}
                                onCancelQuery={() =>
                                  setPendingAction({
                                    type: 'cancel',
                                    connection,
                                  })
                                }
                                onTerminateConnection={() =>
                                  setPendingAction({
                                    type: 'terminate',
                                    connection,
                                  })
                                }
                              />
                            </div>
                          </TableCell>
                          </TableRow>
                        </PostgresConnectionContextMenu>
                      )
                    })}
                  </TableBody>
                </Table>
              </div>
              <div className="h-[54px] shrink-0 border-t border-border bg-background px-4 sm:px-6">
                <div className="flex h-full items-center justify-between gap-3 py-3">
                  <p className="text-[13px] text-muted-foreground">
                    {filteredConnections.length === scopedConnections.length
                      ? `${scopedConnections.length} connection${scopedConnections.length === 1 ? '' : 's'}`
                      : `${filteredConnections.length} of ${scopedConnections.length} connections`}
                  </p>
                  <p className="text-[12px] text-muted-foreground">
                    {t('Refreshes every 30 seconds')}
                  </p>
                </div>
              </div>
            </>
          ) : (
            <EmptyState
              icon={Cable}
              title={
                backendScope === 'clients' &&
                clientConnectionCount === 0 &&
                backendConnectionCount > 0
                  ? t('No client connections')
                  : backendScope === 'backends' &&
                      backendConnectionCount === 0 &&
                      clientConnectionCount > 0
                    ? t('No system backends')
                    : stateFilter === 'all' && backendScope === 'clients'
                      ? t('No active connections')
                      : undefined
              }
              description={
                backendScope === 'clients' &&
                clientConnectionCount === 0 &&
                backendConnectionCount > 0
                  ? t('Only PostgreSQL system backends are running. Switch to Backends to inspect them.')
                  : backendScope === 'backends' &&
                      backendConnectionCount === 0 &&
                      clientConnectionCount > 0
                    ? t('Switch to Clients to inspect application sessions.')
                    : stateFilter === 'all' && backendScope === 'clients'
                      ? t('Client sessions will appear here when applications connect to this instance.')
                      : undefined
              }
              isEmpty={
                backendScope === 'clients'
                  ? clientConnectionCount === 0
                  : backendConnectionCount === 0
              }
              hasFilters={
                (backendScope === 'clients' && stateFilter !== 'all') ||
                (backendScope === 'clients' &&
                  clientConnectionCount > 0 &&
                  filteredConnections.length === 0)
              }
              variant="centered"
            />
          )}
        </div>
      </div>

      <PostgresConnectionDrawer
        open={drawerOpen}
        onOpenChange={(open) => {
          if (open) {
            setDrawerOpen(true)
            return
          }
          closeDrawer()
        }}
        connection={selectedConnection}
        canManageConnections={canManageConnections}
        manageDisabledTooltip={manageDisabledTooltip}
        onOpenInSqlEditor={openInSqlEditor}
        onCancelQuery={(connection) =>
          setPendingAction({ type: 'cancel', connection })
        }
        onTerminateConnection={(connection) =>
          setPendingAction({ type: 'terminate', connection })
        }
        isCancelPending={cancelMutation.isPending}
        isTerminatePending={
          terminateMutation.isPending || terminateIdleMutation.isPending
        }
      />

      <AlertDialog
        open={pendingAction != null}
        onOpenChange={(open) => {
          if (!open) setPendingAction(null)
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {pendingAction?.type === 'cancel'
                ? t('Cancel query?')
                : pendingAction?.type === 'terminate'
                  ? t('Terminate connection?')
                  : t('Terminate idle in transaction connections?')}
            </AlertDialogTitle>
            <AlertDialogDescription className="text-[13px]">
              {pendingAction?.type === 'cancel' ? (
                <>
                  {t('Cancel the active query for PID')}{' '}
                  <span className="font-mono">
                    {pendingAction.connection.pid}
                  </span>
                  {'. '}
                  {t('The client session will stay connected.')}
                </>
              ) : pendingAction?.type === 'terminate' ? (
                <>
                  {t('Terminate the client session for PID')}{' '}
                  <span className="font-mono">
                    {pendingAction.connection.pid}
                  </span>
                  {'. '}
                  {t('The client will need to reconnect.')}
                </>
              ) : (
                <>
                  {t('Terminate')} {idleInTransactionCount}{' '}
                  {idleInTransactionCount === 1
                    ? t('connection currently idle in transaction.')
                    : t('connections currently idle in transaction.')}{' '}
                  {t('Open transactions will be rolled back.')}
                </>
              )}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={actionPending}>{t('Cancel')}</AlertDialogCancel>
            <Button
              disabled={actionPending}
              onClick={() => void runPendingAction()}
            >
              {pendingAction?.type === 'cancel' ? (
                <>
                  <StopCircle className="me-1.5 h-3.5 w-3.5" />
                  {t('Cancel query')}
                </>
              ) : (
                <>
                  <Unplug className="me-1.5 h-3.5 w-3.5" />
                  {t('Terminate')}
                </>
              )}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
