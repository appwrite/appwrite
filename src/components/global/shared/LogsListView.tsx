import { useEffect, useLayoutEffect, useRef, useState, Fragment } from 'react'
import { useNavigate, useLocation } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Zap, Clock } from 'lucide-react'
import { cn } from '@/lib/utils'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { CopyableId } from '@/components/global/shared/CopyableId'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { ExecutionDetailsDrawer } from '@/components/pages/projects/$projectId/functions/ExecutionDetailsDrawer'
import {
  ExecutionRowContextMenu,
  type ExecutionRowContextMenuVariant,
} from '@/components/global/shared/ExecutionRowContextMenu'
import {
  getExecutionStatusBadge,
  getStatusCodeBadge,
} from '@/components/pages/projects/$projectId/functions/Executions'
import {
  deleteFunctionExecution,
  deleteSiteLog,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'

function formatDuration(ms: number): string {
  if (ms < 1000) return `${Math.round(ms)}ms`
  const seconds = ms / 1000
  if (seconds < 60) return `${Number(seconds.toFixed(2))}s`
  const minutes = Math.floor(seconds / 60)
  const secs = Number((seconds % 60).toFixed(2))
  return `${minutes}m ${secs}s`
}

function formatRequestMethod(method: string | undefined): string {
  if (!method?.trim()) return 'N/A'
  return method.toUpperCase()
}

function getTriggerBadge(trigger: string) {
  const triggerMap: Record<
    string,
    { label: string; variant: 'default' | 'secondary' | 'outline' }
  > = {
    http: { label: 'HTTP', variant: 'default' },
    schedule: { label: 'Schedule', variant: 'secondary' },
    event: { label: 'Event', variant: 'outline' },
    manual: { label: 'Manual', variant: 'outline' },
  }
  return (
    triggerMap[trigger?.toLowerCase()] || {
      label: trigger || 'HTTP',
      variant: 'default',
    }
  )
}

function LogsTableColGroup({ showCheckbox }: { showCheckbox: boolean }) {
  return (
    <colgroup>
      {showCheckbox ? <col className="w-10" /> : null}
      <col className="w-[11rem]" />
      <col className="w-[11rem]" />
      <col className="w-[6.5rem]" />
      <col className="w-[5.5rem]" />
      <col className="w-[8.5rem]" />
      <col className="w-[5rem]" />
      <col />
      <col className="w-[7rem]" />
      <col className="w-[8rem]" />
    </colgroup>
  )
}

const logsTableClassName = 'w-full min-w-[77rem] table-fixed'

function LogsTableHead({
  showCheckbox,
  allSelected,
  someSelected,
  onToggleAll,
}: {
  showCheckbox: boolean
  allSelected?: boolean
  someSelected?: boolean
  onToggleAll?: () => void
}) {
  const t = useT()
  return (
    <TableHeader>
      <TableRow className="hover:bg-transparent border-b border-border">
        {showCheckbox ? (
          <TableHead className="sticky top-0 z-10 w-[40px] bg-background px-4 shadow-[inset_0_-1px_0_var(--border)]">
            <Checkbox
              checked={
                allSelected ? true : someSelected ? 'indeterminate' : false
              }
              onCheckedChange={onToggleAll}
              aria-label={t('Select all')}
            />
          </TableHead>
        ) : null}
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 ps-6 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)] sm:ps-8">
          {t('Execution ID')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          {t('Deployment ID')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          {t('Status')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          {t('Trigger')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          {t('Status Code')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          {t('Method')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          {t('Path')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          {t('Duration')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 pe-6 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)] sm:pe-8">
          {t('Created')}
        </TableHead>
      </TableRow>
    </TableHeader>
  )
}

function LogsSkeletonRows({
  rowCount,
  showCheckbox,
}: {
  rowCount: number
  showCheckbox: boolean
}) {
  return (
    <>
      {Array.from({ length: rowCount }, (_, i) => (
        <TableRow
          key={i}
          className="pointer-events-none hover:bg-transparent"
          aria-hidden
        >
          {showCheckbox ? (
            <TableCell className="px-4 py-3">
              <Skeleton className="h-4 w-4" />
            </TableCell>
          ) : null}
          <TableCell className="min-w-0 px-4 py-3 ps-6 sm:ps-8">
            <Skeleton className="h-4 w-32 max-w-full" />
          </TableCell>
          <TableCell className="min-w-0 px-4 py-3">
            <Skeleton className="h-4 w-32 max-w-full" />
          </TableCell>
          <TableCell className="min-w-0 px-4 py-3">
            <Skeleton className="h-5 w-20 rounded-full" />
          </TableCell>
          <TableCell className="min-w-0 px-4 py-3">
            <Skeleton className="h-5 w-16 rounded px-1.5" />
          </TableCell>
          <TableCell className="min-w-0 px-4 py-3">
            <Skeleton className="h-5 w-10 rounded-full" />
          </TableCell>
          <TableCell className="min-w-0 px-4 py-3">
            <Skeleton className="h-5 w-12 rounded px-1.5" />
          </TableCell>
          <TableCell className="min-w-0 px-4 py-3">
            <Skeleton className="h-3.5 w-full max-w-[16rem]" />
          </TableCell>
          <TableCell className="min-w-0 px-4 py-3">
            <Skeleton className="h-3.5 w-14" />
          </TableCell>
          <TableCell className="min-w-0 px-4 py-3 pe-6 sm:pe-8">
            <Skeleton className="h-3.5 w-[6.5rem]" />
          </TableCell>
        </TableRow>
      ))}
    </>
  )
}

function LogsPaginationSkeleton() {
  return (
    <div className="h-[54px] shrink-0 border-t border-border bg-background px-4 sm:px-6">
      <div className="@container flex h-full min-h-8 w-full items-center justify-between gap-2 py-3">
        <div className="flex min-w-0 items-center gap-3">
          <Skeleton className="hidden h-4 w-36 @[600px]:block" />
          <div className="hidden items-center gap-2 @[800px]:flex">
            <Skeleton className="h-4 w-8" />
            <Skeleton className="h-8 w-[72px] rounded-md" />
            <Skeleton className="h-4 w-14" />
          </div>
        </div>
        <Skeleton className="h-8 w-[200px] max-w-[45%] shrink-0 rounded-md" />
      </div>
    </div>
  )
}

function LogsLoadingTable({
  rowCount,
  showCheckbox,
}: {
  rowCount: number
  showCheckbox: boolean
}) {
  const t = useT()
  return (
    <>
      <div
        className="relative min-h-0 min-w-0 flex-1 overflow-auto"
        role="status"
        aria-live="polite"
        aria-busy="true"
        aria-label={t('Loading logs')}
      >
        <Table withScrollContainer={false} className={logsTableClassName}>
          <LogsTableColGroup showCheckbox={showCheckbox} />
          <LogsTableHead showCheckbox={showCheckbox} />
          <TableBody>
            <LogsSkeletonRows
              rowCount={rowCount}
              showCheckbox={showCheckbox}
            />
          </TableBody>
        </Table>
      </div>
      <LogsPaginationSkeleton />
    </>
  )
}

function LogsPaginationFooter({
  currentPage,
  total,
  pageSize,
  onPageChange,
  onPageSizeChange,
  itemLabel,
}: {
  currentPage: number
  total: number
  pageSize: number
  onPageChange: (page: number) => void
  onPageSizeChange: (size: number) => void
  itemLabel: string
}) {
  return (
    <div className="h-[54px] shrink-0 border-t border-border bg-background px-4 sm:px-6">
      <Pagination
        currentPage={currentPage}
        totalItems={total}
        pageSize={pageSize}
        pageSizeOptions={[10, 25, 50, 100]}
        onPageChange={onPageChange}
        onPageSizeChange={onPageSizeChange}
        itemLabel={itemLabel}
        className="mt-0 h-full min-h-0 border-0 py-0"
      />
    </div>
  )
}

interface LogsListViewProps {
  executions: Models.Execution[]
  total: number
  isLoading: boolean
  currentPage: number
  pageSize: number
  onPageChange: (page: number) => void
  onPageSizeChange: (size: number) => void
  selectedExecutionId: string | null
  onExecutionSelect: (executionId: string) => void
  onExecutionDeselect: () => void
  func?: Models.Function | null
  emptyStateTitle?: string
  emptyStateDescription?: string
  emptyStateAction?: React.ReactNode
  hasFilters?: boolean
  itemLabel?: string
  isFetching?: boolean
  projectId?: string
  resourceVariant?: ExecutionRowContextMenuVariant
  resourceId?: string
}

export function LogsListView({
  executions,
  total,
  isLoading,
  currentPage,
  pageSize,
  onPageChange,
  onPageSizeChange,
  selectedExecutionId,
  onExecutionSelect,
  onExecutionDeselect,
  func = null,
  emptyStateTitle = 'No executions yet',
  emptyStateDescription = 'Executions will appear here when your function runs.',
  emptyStateAction,
  hasFilters = false,
  itemLabel = 'executions',
  isFetching: _isFetching = false,
  projectId,
  resourceVariant,
  resourceId,
}: LogsListViewProps) {
  const t = useT()
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()
  const scrollContainerRef = useRef<HTMLDivElement>(null)
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const canDelete = Boolean(projectId && resourceVariant && resourceId)
  const selectedExecution =
    executions.find((e) => e.$id === selectedExecutionId) || null
  const drawerOpen = selectedExecutionId !== null
  const allSelected =
    executions.length > 0 && executions.every((e) => selectedIds.has(e.$id))
  const someSelected = selectedIds.size > 0 && !allSelected
  const singularLabel = resourceVariant === 'site' ? 'log' : 'execution'
  const pluralLabel = resourceVariant === 'site' ? 'logs' : 'executions'

  useEffect(() => {
    setSelectedIds(new Set())
  }, [currentPage, pageSize, projectId, resourceId, resourceVariant])

  useLayoutEffect(() => {
    if (executions.length > 0 && currentPage !== undefined) {
      const container = scrollContainerRef.current
      if (container) {
        container.scrollTop = 0
      }
    }
  }, [currentPage, executions.length])

  const handleNavigate = (executionId: string) => {
    onExecutionSelect(executionId)
    navigate({
      to: location.pathname,
      search: (prev) => ({
        ...prev,
        executionId,
      }),
      replace: true,
    })
  }

  const handleDrawerClose = (open: boolean) => {
    if (!open) {
      navigate({
        to: location.pathname,
        search: (prev) => ({
          ...prev,
          executionId: undefined,
        }),
        replace: true,
      })
      onExecutionDeselect()
    }
  }

  const handleDeleted = (executionId: string) => {
    setSelectedIds((prev) => {
      if (!prev.has(executionId)) return prev
      const next = new Set(prev)
      next.delete(executionId)
      return next
    })
    if (selectedExecutionId === executionId) {
      handleDrawerClose(false)
    }
  }

  const toggleAll = () => {
    if (allSelected) {
      setSelectedIds(new Set())
      return
    }
    setSelectedIds(new Set(executions.map((e) => e.$id)))
  }

  const toggleOne = (executionId: string) => {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (next.has(executionId)) next.delete(executionId)
      else next.add(executionId)
      return next
    })
  }

  const bulkDeleteMutation = useMutation({
    mutationFn: async (ids: string[]) => {
      if (!projectId || !resourceId || !resourceVariant) {
        throw new Error('Project ID and resource ID are required')
      }
      await Promise.all(
        ids.map((id) =>
          resourceVariant === 'function'
            ? deleteFunctionExecution(projectId, resourceId, id)
            : deleteSiteLog(projectId, resourceId, id),
        ),
      )
    },
    onSuccess: async (_data, ids) => {
      if (resourceVariant === 'function') {
        await queryClient.refetchQueries({
          queryKey: ['executions', 'function', projectId, resourceId],
        })
      } else {
        await queryClient.refetchQueries({
          queryKey: ['logs', 'site', projectId, resourceId],
        })
      }
      toast.success(
        `${t('Successfully deleted')} ${ids.length} ${t(
          ids.length > 1 ? pluralLabel : singularLabel,
        )}`,
      )
      setSelectedIds(new Set())
      setDeleteDialogOpen(false)
      if (selectedExecutionId && ids.includes(selectedExecutionId)) {
        handleDrawerClose(false)
      }
    },
    onError: (error: Error) => {
      toast.error(
        getErrorMessage(error) ||
          t(
            resourceVariant === 'site'
              ? 'Failed to delete logs'
              : 'Failed to delete executions',
          ),
      )
    },
  })

  if (isLoading && executions.length === 0) {
    return (
      <div className="flex min-h-0 min-w-0 flex-1 flex-col">
        <LogsLoadingTable rowCount={pageSize} showCheckbox={canDelete} />
      </div>
    )
  }

  return (
    <div className="flex min-h-0 min-w-0 flex-1 flex-col">
      {executions.length > 0 ? (
        <>
          <div
            ref={scrollContainerRef}
            className="relative min-h-0 min-w-0 flex-1 overflow-auto"
          >
            <Table withScrollContainer={false} className={logsTableClassName}>
              <LogsTableColGroup showCheckbox={canDelete} />
              <LogsTableHead
                showCheckbox={canDelete}
                allSelected={allSelected}
                someSelected={someSelected}
                onToggleAll={toggleAll}
              />
              <TableBody>
                {executions.map((execution) => {
                  const executionData = execution as Models.Execution
                  const statusBadge = getExecutionStatusBadge(
                    executionData.status || 'completed',
                  )
                  const triggerBadge = getTriggerBadge(
                    executionData.trigger || 'http',
                  )
                  const statusCodeBadge = executionData.responseStatusCode
                    ? getStatusCodeBadge(executionData.responseStatusCode)
                    : null

                  const executionId = executionData.$id
                  const deploymentId = executionData.deploymentId
                  const method = formatRequestMethod(executionData.requestMethod)
                  const path = executionData.requestPath || 'N/A'
                  const isSelected =
                    drawerOpen && selectedExecutionId === executionId
                  const isChecked = selectedIds.has(executionId)

                  const row = (
                    <TableRow
                      role="button"
                      tabIndex={0}
                      data-state={isSelected ? 'selected' : undefined}
                      className={cn(
                        'cursor-pointer',
                        isSelected && 'bg-muted/60 hover:bg-muted/60',
                      )}
                      onClick={() => onExecutionSelect(executionId)}
                      onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ' ') {
                          e.preventDefault()
                          onExecutionSelect(executionId)
                        }
                      }}
                    >
                      {canDelete ? (
                        <TableCell
                          className="px-4 py-3"
                          onClick={(e) => e.stopPropagation()}
                          onKeyDown={(e) => e.stopPropagation()}
                        >
                          <Checkbox
                            checked={isChecked}
                            onCheckedChange={() => toggleOne(executionId)}
                            aria-label={t('Select row')}
                          />
                        </TableCell>
                      ) : null}
                      <TableCell className="min-w-0 px-4 py-3 ps-6 sm:ps-8">
                        <CopyableId
                          id={executionId}
                          size="sm"
                          constrainToContainer
                        />
                      </TableCell>
                      <TableCell className="min-w-0 px-4 py-3">
                        {deploymentId ? (
                          <CopyableId
                            id={deploymentId}
                            size="sm"
                            constrainToContainer
                          />
                        ) : (
                          <span className="text-[12px] text-muted-foreground">
                            -
                          </span>
                        )}
                      </TableCell>
                      <TableCell className="min-w-0 px-4 py-3">
                        <Badge variant={statusBadge.variant}>
                          {t(statusBadge.label)}
                        </Badge>
                      </TableCell>
                      <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
                        <code className="rounded bg-muted/50 px-1.5 py-0.5 text-[12px] font-mono text-foreground">
                          {t(triggerBadge.label)}
                        </code>
                      </TableCell>
                      <TableCell className="min-w-0 px-4 py-3">
                        {executionData.responseStatusCode ? (
                          <Badge
                            variant={statusCodeBadge?.variant || 'outline'}
                          >
                            {executionData.responseStatusCode}
                          </Badge>
                        ) : (
                          <span className="text-[12px] text-muted-foreground">
                            -
                          </span>
                        )}
                      </TableCell>
                      <TableCell className="min-w-0 px-4 py-3">
                        <code className="rounded bg-muted/50 px-1.5 py-0.5 text-[12px] font-mono text-foreground">
                          {method}
                        </code>
                      </TableCell>
                      <TableCell className="min-w-0 px-4 py-3">
                        {path !== 'N/A' ? (
                          <div
                            className="inline-block max-w-full min-w-0"
                            title={path}
                          >
                            <CopyableId
                              id={path}
                              size="md"
                              variant="inline"
                              constrainToContainer
                              className="w-full max-w-full"
                            />
                          </div>
                        ) : (
                          <span className="text-[12px] text-muted-foreground">
                            -
                          </span>
                        )}
                      </TableCell>
                      <TableCell className="min-w-0 px-4 py-3">
                        <div className="flex items-center gap-2">
                          <Clock className="h-4 w-4 shrink-0 text-muted-foreground" />
                          <code className="text-[12px] font-mono text-muted-foreground">
                            {executionData.duration
                              ? formatDuration(executionData.duration * 1000)
                              : '-'}
                          </code>
                        </div>
                      </TableCell>
                      <TableCell className="min-w-0 px-4 py-3 pe-6 sm:pe-8">
                        <DateTooltip
                          date={executionData.$createdAt}
                          className="text-[12px] text-muted-foreground"
                        />
                      </TableCell>
                    </TableRow>
                  )

                  if (projectId && resourceVariant && resourceId) {
                    return (
                      <ExecutionRowContextMenu
                        key={executionId}
                        variant={resourceVariant}
                        projectId={projectId}
                        resourceId={resourceId}
                        execution={executionData}
                        onOpenDetails={() => onExecutionSelect(executionId)}
                        onDeleted={handleDeleted}
                      >
                        {row}
                      </ExecutionRowContextMenu>
                    )
                  }

                  return <Fragment key={executionId}>{row}</Fragment>
                })}
              </TableBody>
            </Table>
          </div>
          <LogsPaginationFooter
            currentPage={currentPage}
            total={total}
            pageSize={pageSize}
            onPageChange={onPageChange}
            onPageSizeChange={onPageSizeChange}
            itemLabel={itemLabel}
          />
        </>
      ) : (
        <div className="flex flex-1 flex-col items-center justify-center py-16">
          <EmptyState
            icon={Zap}
            title={t(emptyStateTitle)}
            description={t(emptyStateDescription)}
            isEmpty={!hasFilters}
            hasFilters={hasFilters}
            variant="centered"
            iconSize="md"
          />
          {emptyStateAction && (
            <div className="mt-4 flex justify-center">{emptyStateAction}</div>
          )}
        </div>
      )}

      {selectedIds.size > 0 && (
        <div className="fixed bottom-4 start-1/2 z-50 -translate-x-1/2">
          <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
            <Badge variant="secondary" className="h-6 px-2.5">
              {selectedIds.size}{' '}
              {t(selectedIds.size > 1 ? pluralLabel : singularLabel)}{' '}
              {t('selected')}
            </Badge>
            <div className="flex items-center gap-2">
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setSelectedIds(new Set())}
                className="h-8 text-xs"
              >
                {t('Cancel')}
              </Button>
              <Button
                variant="destructive"
                size="sm"
                onClick={() => setDeleteDialogOpen(true)}
                disabled={bulkDeleteMutation.isPending}
                className="h-8 gap-2"
              >
                {t('Delete')}
              </Button>
            </div>
          </div>
        </div>
      )}

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>
              {t(
                selectedIds.size > 1
                  ? resourceVariant === 'site'
                    ? 'Delete logs'
                    : 'Delete executions'
                  : resourceVariant === 'site'
                    ? 'Delete log'
                    : 'Delete execution',
              )}
            </DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to delete')} {selectedIds.size}{' '}
              {t(selectedIds.size > 1 ? pluralLabel : singularLabel)}?{' '}
              {t('This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={bulkDeleteMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={() =>
                bulkDeleteMutation.mutate(Array.from(selectedIds))
              }
              disabled={bulkDeleteMutation.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <ExecutionDetailsDrawer
        open={drawerOpen}
        onOpenChange={handleDrawerClose}
        execution={selectedExecution as Models.Execution | null}
        executions={executions as Models.Execution[]}
        func={func}
        onNavigate={handleNavigate}
        onDeleted={handleDeleted}
        projectId={projectId}
        resourceVariant={resourceVariant}
        resourceId={resourceId}
      />
    </div>
  )
}
