import { useState, useLayoutEffect, useRef, Fragment } from 'react'
import { useNavigate, useLocation } from '@tanstack/react-router'
import { Zap, Clock, Copy, Check } from 'lucide-react'
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
import { Skeleton } from '@/components/ui/skeleton'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { ExecutionDetailsDrawer } from '@/components/pages/projects/$projectId/functions/ExecutionDetailsDrawer'
import {
  ExecutionRowContextMenu,
  type ExecutionRowContextMenuVariant,
} from '@/components/global/shared/ExecutionRowContextMenu'
import {
  getExecutionStatusBadge,
  getStatusCodeBadge,
} from '@/components/pages/projects/$projectId/functions/Executions'
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

function LogsTableColGroup() {
  return (
    <colgroup>
      <col className="w-[12%]" />
      <col className="w-[12%]" />
      <col className="w-[9%]" />
      <col className="w-[7%]" />
      <col className="w-[8%]" />
      <col className="w-[7%]" />
      <col className="" />
      <col className="w-[9%]" />
      <col className="w-[10%]" />
    </colgroup>
  )
}

function LogsTableHead() {
  return (
    <TableHeader>
      <TableRow className="hover:bg-transparent border-b border-border">
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 pl-6 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)] sm:pl-8">
          Execution ID
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          Deployment ID
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          Status
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          Trigger
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          Status Code
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          Method
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          Path
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]">
          Duration
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 pr-6 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)] sm:pr-8">
          Created
        </TableHead>
      </TableRow>
    </TableHeader>
  )
}

function LogsSkeletonRows({ rowCount }: { rowCount: number }) {
  return (
    <>
      {Array.from({ length: rowCount }, (_, i) => (
        <TableRow
          key={i}
          className="pointer-events-none hover:bg-transparent"
          aria-hidden
        >
          <TableCell className="min-w-0 px-4 py-3 pl-6 sm:pl-8">
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
          <TableCell className="min-w-0 px-4 py-3 pr-6 sm:pr-8">
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

function LogsLoadingTable({ rowCount }: { rowCount: number }) {
  return (
    <>
      <div
        className="relative min-h-0 flex-1 overflow-auto"
        role="status"
        aria-live="polite"
        aria-busy="true"
        aria-label="Loading logs"
      >
        <Table withScrollContainer={false} className="table-fixed w-full">
          <LogsTableColGroup />
          <LogsTableHead />
          <TableBody>
            <LogsSkeletonRows rowCount={rowCount} />
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
  isFetching = false,
  projectId,
  resourceVariant,
  resourceId,
}: LogsListViewProps) {
  const navigate = useNavigate()
  const location = useLocation()
  const scrollContainerRef = useRef<HTMLDivElement>(null)
  const [copiedField, setCopiedField] = useState<string | null>(null)

  const selectedExecution =
    executions.find((e) => e.$id === selectedExecutionId) || null
  const drawerOpen = selectedExecutionId !== null

  const handleCopy = async (text: string, fieldId: string) => {
    try {
      await navigator.clipboard.writeText(text)
      setCopiedField(fieldId)
      setTimeout(() => setCopiedField(null), 2000)
    } catch {
      // Ignore copy errors
    }
  }

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

  if (isLoading || isFetching) {
    return (
      <div className="flex min-h-0 flex-1 flex-col">
        <LogsLoadingTable rowCount={pageSize} />
      </div>
    )
  }

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      {executions.length > 0 ? (
        <>
          <div
            ref={scrollContainerRef}
            className="relative min-h-0 flex-1 overflow-auto"
          >
            <Table withScrollContainer={false} className="table-fixed w-full">
              <LogsTableColGroup />
              <LogsTableHead />
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
                      <TableCell className="min-w-0 px-4 py-3 pl-6 sm:pl-8">
                        <div className="group/id flex items-center gap-2">
                          <CopyableId id={executionId} size="sm" />
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-6 w-6 shrink-0 cursor-pointer p-0 opacity-0 transition-opacity group-hover/id:opacity-100"
                            onClick={(e) => {
                              e.stopPropagation()
                              handleCopy(executionId, `id-${executionId}`)
                            }}
                          >
                            {copiedField === `id-${executionId}` ? (
                              <Check className="h-3 w-3 text-emerald-500" />
                            ) : (
                              <Copy className="h-3 w-3" />
                            )}
                          </Button>
                        </div>
                      </TableCell>
                      <TableCell className="min-w-0 px-4 py-3">
                        {deploymentId ? (
                          <div className="group/deployment flex items-center gap-2">
                            <CopyableId id={deploymentId} size="sm" />
                            <Button
                              variant="ghost"
                              size="sm"
                              className="h-6 w-6 shrink-0 cursor-pointer p-0 opacity-0 transition-opacity group-hover/deployment:opacity-100"
                              onClick={(e) => {
                                e.stopPropagation()
                                handleCopy(
                                  deploymentId,
                                  `deployment-${executionId}`,
                                )
                              }}
                            >
                              {copiedField === `deployment-${executionId}` ? (
                                <Check className="h-3 w-3 text-emerald-500" />
                              ) : (
                                <Copy className="h-3 w-3" />
                              )}
                            </Button>
                          </div>
                        ) : (
                          <span className="text-[12px] text-muted-foreground">
                            -
                          </span>
                        )}
                      </TableCell>
                      <TableCell className="min-w-0 px-4 py-3">
                        <Badge variant={statusBadge.variant}>
                          {statusBadge.label}
                        </Badge>
                      </TableCell>
                      <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
                        <code className="rounded bg-muted/50 px-1.5 py-0.5 text-[12px] font-mono text-foreground">
                          {triggerBadge.label}
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
                        <div className="group/path flex min-w-0 items-center gap-2">
                          {path !== 'N/A' ? (
                            <>
                              {path.length > 40 ? (
                                <TooltipProvider delayDuration={0}>
                                  <Tooltip>
                                    <TooltipTrigger asChild>
                                      <code
                                        className="block min-w-0 cursor-pointer truncate text-[12px] font-mono text-foreground"
                                        onClick={(e) => e.stopPropagation()}
                                      >
                                        {path}
                                      </code>
                                    </TooltipTrigger>
                                    <TooltipContent
                                      side="top"
                                      className="max-w-md"
                                    >
                                      <p className="whitespace-pre-wrap break-words font-mono text-[12px]">
                                        {path}
                                      </p>
                                    </TooltipContent>
                                  </Tooltip>
                                </TooltipProvider>
                              ) : (
                                <code className="min-w-0 break-all text-[12px] font-mono text-foreground">
                                  {path}
                                </code>
                              )}
                              <Button
                                variant="ghost"
                                size="sm"
                                className="h-6 w-6 shrink-0 cursor-pointer p-0 opacity-0 transition-opacity group-hover/path:opacity-100"
                                onClick={(e) => {
                                  e.stopPropagation()
                                  handleCopy(path, `path-${executionId}`)
                                }}
                              >
                                {copiedField === `path-${executionId}` ? (
                                  <Check className="h-3 w-3 text-emerald-500" />
                                ) : (
                                  <Copy className="h-3 w-3" />
                                )}
                              </Button>
                            </>
                          ) : (
                            <span className="text-[12px] text-muted-foreground">
                              -
                            </span>
                          )}
                        </div>
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
                      <TableCell className="min-w-0 px-4 py-3 pr-6 sm:pr-8">
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
                      >
                        {row}
                      </ExecutionRowContextMenu>
                    )
                  }

                  return (
                    <Fragment key={executionId}>{row}</Fragment>
                  )
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
            title={emptyStateTitle}
            description={emptyStateDescription}
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

      <ExecutionDetailsDrawer
        open={drawerOpen}
        onOpenChange={handleDrawerClose}
        execution={selectedExecution as Models.Execution | null}
        executions={executions as Models.Execution[]}
        func={func}
        onNavigate={handleNavigate}
        projectId={projectId}
        resourceVariant={resourceVariant}
        resourceId={resourceId}
      />
    </div>
  )
}
