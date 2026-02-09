import { useState, useLayoutEffect, useRef } from 'react'
import { useNavigate, useLocation } from '@tanstack/react-router'
import { Zap, Clock, Copy, Check } from 'lucide-react'
import { cn } from '@/lib/utils'
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
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { ExecutionDetailsDrawer } from '@/components/pages/projects/$projectId/functions/ExecutionDetailsDrawer'
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

interface LogsListViewProps {
  executions: Models.Execution[]
  total: number
  isLoading: boolean
  currentPage: number // 1-indexed
  pageSize: number
  onPageChange: (page: number) => void // receives 1-indexed page
  onPageSizeChange: (size: number) => void
  selectedExecutionId: string | null
  onExecutionSelect: (executionId: string) => void
  onExecutionDeselect: () => void
  func?: Models.Function | null
  emptyStateTitle?: string
  emptyStateDescription?: string
  itemLabel?: string
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
  itemLabel = 'executions',
}: LogsListViewProps) {
  const navigate = useNavigate()
  const location = useLocation()
  const scrollContainerRef = useRef<HTMLDivElement>(null)
  const [copiedField, setCopiedField] = useState<string | null>(null)

  const selectedExecution =
    executions.find((e) => e.$id === selectedExecutionId) || null

  const handleCopy = async (text: string, fieldId: string) => {
    try {
      await navigator.clipboard.writeText(text)
      setCopiedField(fieldId)
      setTimeout(() => setCopiedField(null), 2000)
    } catch {
      // Ignore copy errors
    }
  }

  // Scroll to top when page changes and data is ready
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
      // Navigate to remove executionId from URL
      // The parent component's useEffect will sync and clear selectedExecutionId
      navigate({
        to: location.pathname,
        search: (prev) => ({
          ...prev,
          executionId: undefined,
        }),
        replace: true,
      })
      // Also clear selection immediately to prevent any race conditions
      onExecutionDeselect()
    }
  }

  // Only show full loading state on initial load when there's no data
  if (isLoading && executions.length === 0) {
    return (
      <div className="flex-1">
        <div className="flex h-full items-center justify-center py-16">
          <div className="text-center">
            <p className="text-[13px] text-muted-foreground">Loading logs...</p>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div ref={scrollContainerRef} className="flex-1">
      {executions.length > 0 ? (
        <>
          <Table className="border-b border-border">
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[200px] pl-6 sm:pl-8">
                  Execution ID
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[200px]">
                  Status
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[150px]">
                  Trigger
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[120px]">
                  Status Code
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[120px]">
                  Method
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[280px]">
                  Path
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[150px]">
                  Duration
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[140px]">
                  Created
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {executions.map((execution, index) => {
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
                const method = executionData.requestMethod || 'N/A'
                const path = executionData.requestPath || 'N/A'

                return (
                  <TableRow
                    key={executionId}
                    className={cn(
                      'cursor-pointer',
                      index === executions.length - 1 &&
                        'border-b border-border',
                    )}
                    onClick={() => onExecutionSelect(executionId)}
                  >
                    <TableCell className="pl-6 sm:pl-8 py-3">
                      <div className="flex items-center gap-2 group/id">
                        <CopyableId id={executionId} size="sm" />
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-6 w-6 p-0 opacity-0 group-hover/id:opacity-100 transition-opacity cursor-pointer shrink-0"
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
                    <TableCell className="px-4 py-3">
                      <Badge variant={statusBadge.variant}>
                        {statusBadge.label}
                      </Badge>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <code className="text-[12px] font-mono text-foreground bg-muted/50 px-1.5 py-0.5 rounded">
                        {triggerBadge.label}
                      </code>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      {executionData.responseStatusCode ? (
                        <Badge variant={statusCodeBadge?.variant || 'outline'}>
                          {executionData.responseStatusCode}
                        </Badge>
                      ) : (
                        <span className="text-[12px] text-muted-foreground">
                          —
                        </span>
                      )}
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <div className="flex items-center gap-2 group/method">
                        <code className="text-[12px] font-mono text-foreground bg-muted/50 px-1.5 py-0.5 rounded">
                          {method}
                        </code>
                        {method !== 'N/A' && (
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-6 w-6 p-0 opacity-0 group-hover/method:opacity-100 transition-opacity cursor-pointer shrink-0"
                            onClick={(e) => {
                              e.stopPropagation()
                              handleCopy(method, `method-${executionId}`)
                            }}
                          >
                            {copiedField === `method-${executionId}` ? (
                              <Check className="h-3 w-3 text-emerald-500" />
                            ) : (
                              <Copy className="h-3 w-3" />
                            )}
                          </Button>
                        )}
                      </div>
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center gap-2 max-w-[280px] group/path">
                        {path !== 'N/A' ? (
                          <>
                            {path.length > 40 ? (
                              <TooltipProvider delayDuration={0}>
                                <Tooltip>
                                  <TooltipTrigger asChild>
                                    <code
                                      className="text-[12px] font-mono text-foreground cursor-pointer truncate block"
                                      onClick={(e) => e.stopPropagation()}
                                    >
                                      {path}
                                    </code>
                                  </TooltipTrigger>
                                  <TooltipContent
                                    side="top"
                                    className="max-w-md"
                                  >
                                    <p className="text-[12px] whitespace-pre-wrap break-words font-mono">
                                      {path}
                                    </p>
                                  </TooltipContent>
                                </Tooltip>
                              </TooltipProvider>
                            ) : (
                              <code className="text-[12px] font-mono text-foreground break-all">
                                {path}
                              </code>
                            )}
                            <Button
                              variant="ghost"
                              size="sm"
                              className="h-6 w-6 p-0 shrink-0 opacity-0 group-hover/path:opacity-100 transition-opacity cursor-pointer"
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
                            —
                          </span>
                        )}
                      </div>
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center gap-2">
                        <Clock className="h-4 w-4 text-muted-foreground shrink-0" />
                        <code className="text-[12px] font-mono text-muted-foreground">
                          {executionData.duration
                            ? formatDuration(executionData.duration * 1000)
                            : '—'}
                        </code>
                      </div>
                    </TableCell>
                    <TableCell>
                      <DateTooltip
                        date={executionData.$createdAt}
                        className="text-[12px] font-medium text-muted-foreground"
                      />
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
          <div className="px-4 py-3 sm:px-6">
            <Pagination
              currentPage={currentPage}
              totalItems={total}
              pageSize={pageSize}
              pageSizeOptions={[10, 25, 50, 100]}
              onPageChange={onPageChange}
              onPageSizeChange={onPageSizeChange}
              itemLabel={itemLabel}
            />
          </div>
        </>
      ) : (
        <div className="flex h-full items-center justify-center py-16">
          <div className="text-center">
            <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted ring-1 ring-border">
              <Zap className="h-5 w-5 text-muted-foreground" />
            </div>
            <p className="mb-1 text-[14px] font-medium text-foreground">
              {emptyStateTitle}
            </p>
            <p className="text-[13px] text-muted-foreground">
              {emptyStateDescription}
            </p>
          </div>
        </div>
      )}

      <ExecutionDetailsDrawer
        open={selectedExecutionId !== null}
        onOpenChange={handleDrawerClose}
        execution={selectedExecution as Models.Execution | null}
        executions={executions as Models.Execution[]}
        func={func}
        onNavigate={handleNavigate}
      />
    </div>
  )
}
