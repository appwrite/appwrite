import { useState, useEffect, useMemo } from 'react'
import {
  ChevronUp,
  ChevronDown,
  Copy,
  Check,
  Search,
  Link2,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { ScrollArea } from '@/components/ui/scroll-area'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import type { Models } from '@appwrite.io/console'
import { getExecutionStatusBadge, getStatusCodeBadge } from './Executions'
import { useParams } from '@tanstack/react-router'

interface ExecutionDetailsDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  execution: Models.Execution | null
  executions: Models.Execution[]
  func: Models.Function | null
  onNavigate: (executionId: string) => void
}

function formatDuration(ms: number): string {
  if (ms < 1000) return `${Math.round(ms)}ms`
  const seconds = ms / 1000
  if (seconds < 60) return `${Number(seconds.toFixed(2))}s`
  const minutes = Math.floor(seconds / 60)
  const secs = Number((seconds % 60).toFixed(2))
  return `${minutes}m ${secs}s`
}

function formatDurationFromSeconds(seconds: number): string {
  if (seconds < 1) return `${Math.round(seconds * 1000)}ms`
  if (seconds < 60) return `${Number(seconds.toFixed(2))}s`
  const minutes = Math.floor(seconds / 60)
  const secs = Number((seconds % 60).toFixed(2))
  return `${minutes}m ${secs}s`
}

function parseQueryParams(
  path: string,
): Array<{ name: string; value: string }> {
  try {
    const url = new URL(path, 'http://dummy.com')
    const params: Array<{ name: string; value: string }> = []
    url.searchParams.forEach((value, name) => {
      params.push({ name, value })
    })
    return params
  } catch {
    return []
  }
}

function capitalizeFirst(str: string): string {
  return str.charAt(0).toUpperCase() + str.slice(1)
}

export function ExecutionDetailsDrawer({
  open,
  onOpenChange,
  execution,
  executions,
  func,
  onNavigate,
}: ExecutionDetailsDrawerProps) {
  useParams({ strict: false })
  const [copiedPath, setCopiedPath] = useState(false)
  const [copiedLink, setCopiedLink] = useState(false)
  const [requestTab, setRequestTab] = useState<'parameters' | 'headers'>(
    'parameters',
  )
  const [responseTab, setResponseTab] = useState<
    'logs' | 'errors' | 'headers' | 'body'
  >('logs')
  const [elapsedTime, setElapsedTime] = useState(0)
  const [logsSearch, setLogsSearch] = useState('')
  const [errorsSearch, setErrorsSearch] = useState('')
  const [bodySearch, setBodySearch] = useState('')

  // Find current execution index
  const currentIndex = useMemo(() => {
    if (!execution) return -1
    return executions.findIndex((e) => e.$id === execution.$id)
  }, [execution, executions])

  const isFirst = currentIndex === 0
  const isLast = currentIndex === executions.length - 1

  // Parse query parameters
  const queryParams = useMemo(() => {
    if (!execution?.requestPath) return []
    return parseQueryParams(execution.requestPath)
  }, [execution?.requestPath])

  // Determine initial response tab
  useEffect(() => {
    if (!execution) return
    if (execution.errors) {
      setResponseTab('errors')
    } else if (execution.logs) {
      setResponseTab('logs')
    } else if (
      execution.responseHeaders &&
      execution.responseHeaders.length > 0
    ) {
      setResponseTab('headers')
    } else {
      setResponseTab('logs')
    }
    // Reset search values when execution changes
    setLogsSearch('')
    setErrorsSearch('')
    setBodySearch('')
  }, [execution])

  // Set initial request tab
  useEffect(() => {
    if (queryParams.length > 0) {
      setRequestTab('parameters')
    } else if (
      execution?.requestHeaders &&
      execution.requestHeaders.length > 0
    ) {
      setRequestTab('headers')
    }
  }, [queryParams.length, execution?.requestHeaders])

  // Live timer for processing/waiting executions
  useEffect(() => {
    if (!execution || !execution.$createdAt) return
    if (execution.status !== 'processing' && execution.status !== 'waiting') {
      setElapsedTime(0)
      return
    }

    const startTime = new Date(execution.$createdAt).getTime()
    const updateTimer = () => {
      const now = Date.now()
      const elapsed = Math.floor((now - startTime) / 1000)
      setElapsedTime(elapsed)
    }

    updateTimer()
    const interval = setInterval(updateTimer, 1000)

    return () => clearInterval(interval)
  }, [execution])

  if (!execution) return null

  const statusBadge = getExecutionStatusBadge(execution.status)
  const statusCodeBadge = execution.responseStatusCode
    ? getStatusCodeBadge(execution.responseStatusCode)
    : null

  const handlePrevious = () => {
    if (currentIndex > 0) {
      onNavigate(executions[currentIndex - 1].$id)
    }
  }

  const handleNext = () => {
    if (currentIndex < executions.length - 1) {
      onNavigate(executions[currentIndex + 1].$id)
    }
  }

  const handleCopyPath = () => {
    if (execution.requestPath) {
      navigator.clipboard.writeText(execution.requestPath)
      setCopiedPath(true)
      setTimeout(() => setCopiedPath(false), 2000)
    }
  }

  const handleCopyLink = () => {
    if (!execution) return
    const url = new URL(window.location.href)
    url.searchParams.set('executionId', execution.$id)
    navigator.clipboard.writeText(url.toString())
    setCopiedLink(true)
    setTimeout(() => setCopiedLink(false), 2000)
  }

  // Format duration
  const durationDisplay =
    execution.status === 'processing' || execution.status === 'waiting'
      ? formatDurationFromSeconds(elapsedTime)
      : execution.duration
        ? formatDuration(execution.duration * 1000)
        : 'N/A'

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title=""
      maxWidth="sm:max-w-[700px]"
      contentClassName="overflow-hidden"
      disableAutoFocus={true}
      headerActions={
        <>
          <Button
            variant="ghost"
            size="sm"
            className="h-8 w-8 p-0 cursor-pointer"
            onClick={handlePrevious}
            disabled={isFirst}
          >
            <ChevronUp className="h-4 w-4" />
          </Button>
          <Button
            variant="ghost"
            size="sm"
            className="h-8 w-8 p-0 cursor-pointer"
            onClick={handleNext}
            disabled={isLast}
          >
            <ChevronDown className="h-4 w-4" />
          </Button>
          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-8 w-8 p-0 cursor-pointer"
                  onClick={handleCopyLink}
                >
                  {copiedLink ? (
                    <Check className="h-4 w-4 text-emerald-500" />
                  ) : (
                    <Link2 className="h-4 w-4" />
                  )}
                </Button>
              </TooltipTrigger>
              <TooltipContent>
                <p>{copiedLink ? 'Link copied!' : 'Copy link'}</p>
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>
        </>
      }
    >
      <>
        <div className="border-b border-border flex-shrink-0" />

        {/* Content */}
        <ScrollArea className="flex-1 min-h-0">
          <div className="px-6 py-6 space-y-8">
            {/* Details Section */}
            <Accordion
              type="multiple"
              defaultValue={['details', 'request', 'response']}
              className="w-full"
            >
              <AccordionItem value="details" className="border-none">
                <AccordionTrigger className="text-[16px] font-medium py-2 cursor-pointer hover:no-underline">
                  Details
                </AccordionTrigger>
                <AccordionContent className="pt-4 overflow-visible">
                  <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 -mx-1.5 px-1.5">
                    {/* Execution ID */}
                    <div>
                      <p className="text-[14px] text-muted-foreground mb-2">
                        Execution ID
                      </p>
                      <CopyableId id={execution.$id} size="sm" />
                    </div>

                    {/* Deployment ID */}
                    {execution.deploymentId && (
                      <div>
                        <p className="text-[14px] text-muted-foreground mb-2">
                          Deployment ID
                        </p>
                        <CopyableId id={execution.deploymentId} size="sm" />
                      </div>
                    )}

                    {/* Method */}
                    <div>
                      <p className="text-[14px] text-muted-foreground mb-2">
                        Method
                      </p>
                      <p className="text-[14px] text-foreground font-medium">
                        {execution.requestMethod?.toUpperCase() || 'N/A'}
                      </p>
                    </div>

                    {/* Status Code */}
                    <div>
                      <p className="text-[14px] text-muted-foreground mb-2">
                        Status code
                      </p>
                      {execution.responseStatusCode ? (
                        <Badge variant={statusCodeBadge?.variant || 'outline'}>
                          {execution.responseStatusCode}
                        </Badge>
                      ) : (
                        <span className="text-[14px] text-muted-foreground">
                          N/A
                        </span>
                      )}
                    </div>

                    {/* Status */}
                    <div>
                      <p className="text-[14px] text-muted-foreground mb-2">
                        Status
                      </p>
                      <Badge variant={statusBadge.variant}>
                        {statusBadge.label}
                      </Badge>
                    </div>

                    {/* Triggered By */}
                    <div>
                      <p className="text-[14px] text-muted-foreground mb-2">
                        Triggered by
                      </p>
                      <p className="text-[14px] text-foreground">
                        {execution.trigger
                          ? capitalizeFirst(execution.trigger)
                          : 'N/A'}
                      </p>
                    </div>

                    {/* Duration */}
                    <div>
                      <p className="text-[14px] text-muted-foreground mb-2">
                        Duration
                      </p>
                      <p className="text-[14px] text-foreground font-mono">
                        {durationDisplay}
                      </p>
                    </div>

                    {/* Created */}
                    <div>
                      <p className="text-[14px] text-muted-foreground mb-2">
                        Created
                      </p>
                      <DateTooltip date={execution.$createdAt} />
                    </div>

                    {/* Path - Full Width */}
                    <div className="sm:col-span-2 lg:col-span-3">
                      <p className="text-[14px] text-muted-foreground mb-2">
                        Path
                      </p>
                      {execution.requestPath ? (
                        <div className="relative -mx-1 px-1">
                          <Input
                            value={execution.requestPath}
                            readOnly
                            className="pr-10 font-mono text-[13px] bg-muted"
                          />
                          <button
                            type="button"
                            onClick={handleCopyPath}
                            className="absolute right-2 top-1/2 -translate-y-1/2 flex items-center justify-center h-7 w-7 rounded-md hover:bg-accent transition-colors"
                            aria-label="Copy path"
                          >
                            {copiedPath ? (
                              <Check className="h-4 w-4 text-emerald-500" />
                            ) : (
                              <Copy className="h-4 w-4 text-muted-foreground" />
                            )}
                          </button>
                        </div>
                      ) : (
                        <span className="text-[14px] text-muted-foreground">
                          N/A
                        </span>
                      )}
                    </div>
                  </div>
                </AccordionContent>
              </AccordionItem>

              {/* Request Section */}
              <AccordionItem value="request" className="border-none">
                <AccordionTrigger className="text-[16px] font-medium py-2 cursor-pointer hover:no-underline">
                  Request
                </AccordionTrigger>
                <AccordionContent className="pt-4 overflow-visible">
                  <div className="-mx-1.5 px-1.5">
                    <Tabs
                      value={requestTab}
                      onValueChange={(v) =>
                        setRequestTab(v as 'parameters' | 'headers')
                      }
                      className="w-full"
                    >
                      <TabsList className="w-full grid grid-cols-2 h-9 mb-4">
                        <TabsTrigger value="parameters" className="text-[13px]">
                          Parameters
                          {queryParams.length > 0 && (
                            <span className="ml-1.5 text-muted-foreground">
                              ({queryParams.length})
                            </span>
                          )}
                        </TabsTrigger>
                        <TabsTrigger value="headers" className="text-[13px]">
                          Headers
                          {execution.requestHeaders &&
                            execution.requestHeaders.length > 0 && (
                              <span className="ml-1.5 text-muted-foreground">
                                ({execution.requestHeaders.length})
                              </span>
                            )}
                        </TabsTrigger>
                      </TabsList>

                      <TabsContent value="parameters" className="mt-0">
                      <div className="mt-4">
                        {queryParams.length > 0 ? (
                          <div className="rounded-lg border border-border overflow-hidden">
                            <Table>
                              <TableHeader>
                                <TableRow className="hover:bg-transparent border-b border-border">
                                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[200px]">
                                    Key
                                  </TableHead>
                                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                                    Value
                                  </TableHead>
                                </TableRow>
                              </TableHeader>
                              <TableBody>
                                {queryParams.map((param, index) => (
                                  <TableRow key={index}>
                                    <TableCell className="px-4 py-3 font-mono text-[13px]">
                                      {param.name}
                                    </TableCell>
                                    <TableCell className="px-4 py-3 font-mono text-[13px]">
                                      {param.value}
                                    </TableCell>
                                  </TableRow>
                                ))}
                              </TableBody>
                            </Table>
                          </div>
                        ) : (
                          <div className="rounded-lg border border-border bg-card p-3">
                            <code className="text-[13px] text-muted-foreground">
                              No parameters found.
                            </code>
                          </div>
                        )}
                      </div>
                      </TabsContent>

                      <TabsContent value="headers" className="mt-0">
                      <div className="mt-4">
                        {execution.requestHeaders &&
                        execution.requestHeaders.length > 0 ? (
                          <>
                            <div className="rounded-lg border border-border overflow-hidden">
                              <Table>
                                <TableHeader>
                                  <TableRow className="hover:bg-transparent border-b border-border">
                                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[200px]">
                                      Key
                                    </TableHead>
                                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                                      Value
                                    </TableHead>
                                  </TableRow>
                                </TableHeader>
                                <TableBody>
                                  {execution.requestHeaders.map(
                                    (header, index) => (
                                      <TableRow key={index}>
                                        <TableCell className="px-4 py-3 font-mono text-[13px]">
                                          {header.name}
                                        </TableCell>
                                        <TableCell className="px-4 py-3 font-mono text-[13px]">
                                          {header.value}
                                        </TableCell>
                                      </TableRow>
                                    ),
                                  )}
                                </TableBody>
                              </Table>
                            </div>
                            <p className="text-[12px] text-muted-foreground mt-4">
                              Missing headers?{' '}
                              <a
                                href="https://appwrite.io/docs"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-primary hover:underline"
                              >
                                Check the docs
                              </a>{' '}
                              to see the supported data and how to log it.
                            </p>
                          </>
                        ) : (
                          <div className="rounded-lg border border-border bg-card p-3">
                            <code className="text-[13px] text-muted-foreground">
                              No headers found.
                            </code>
                          </div>
                        )}
                      </div>
                      </TabsContent>
                    </Tabs>
                  </div>
                </AccordionContent>
              </AccordionItem>

              {/* Response Section */}
              <AccordionItem value="response" className="border-none">
                <AccordionTrigger className="text-[16px] font-medium py-2 cursor-pointer hover:no-underline">
                  Response
                </AccordionTrigger>
                <AccordionContent className="pt-4 overflow-visible">
                  <div className="-mx-1.5 px-1.5">
                    <Tabs
                      value={responseTab}
                      onValueChange={(v) =>
                        setResponseTab(
                          v as 'logs' | 'errors' | 'headers' | 'body',
                        )
                      }
                      className="w-full"
                    >
                      <TabsList className="w-full grid grid-cols-4 h-9 mb-4">
                        <TabsTrigger value="logs" className="text-[13px]">
                          Logs
                        </TabsTrigger>
                        <TabsTrigger value="errors" className="text-[13px]">
                          Errors
                        </TabsTrigger>
                        <TabsTrigger value="headers" className="text-[13px]">
                          Headers
                          {execution.responseHeaders &&
                            execution.responseHeaders.length > 0 && (
                              <span className="ml-1.5 text-muted-foreground">
                                ({execution.responseHeaders.length})
                              </span>
                            )}
                        </TabsTrigger>
                        <TabsTrigger value="body" className="text-[13px]">
                          Body
                        </TabsTrigger>
                      </TabsList>

                      <TabsContent value="logs" className="mt-0">
                      <div className="mt-4">
                        {func?.logging === false ? (
                          <div className="rounded-lg border border-amber-500/20 bg-amber-500/5 p-4">
                            <p className="text-[13px] text-foreground mb-2">
                              Logging is disabled for this function. Enable
                              logging in settings to view execution logs.
                            </p>
                            <a
                              href="https://appwrite.io/docs"
                              target="_blank"
                              rel="noopener noreferrer"
                              className="text-[12px] text-primary hover:underline"
                            >
                              Learn more →
                            </a>
                          </div>
                        ) : execution.logs ? (
                          <div className="rounded-lg border border-border bg-card p-4">
                            <div className="space-y-3">
                              <div className="relative -mx-1 px-1">
                                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                                <Input
                                  placeholder="Search logs..."
                                  value={logsSearch}
                                  onChange={(e) =>
                                    setLogsSearch(e.target.value)
                                  }
                                  className="pl-9 h-9 text-[13px]"
                                />
                              </div>
                              <ScrollArea className="h-[400px] w-full rounded-lg border border-border">
                                <div className="p-4 min-w-0">
                                  <pre className="text-[12px] font-mono text-foreground whitespace-pre-wrap break-all overflow-x-auto max-w-full min-w-0">
                                    {(() => {
                                      const logsText =
                                        typeof execution.logs === 'string'
                                          ? execution.logs
                                          : Array.isArray(execution.logs)
                                            ? (execution.logs as string[]).join(
                                                '\n',
                                              )
                                            : JSON.stringify(
                                                execution.logs,
                                                null,
                                                2,
                                              )

                                      if (!logsSearch.trim()) return logsText

                                      const searchLower =
                                        logsSearch.toLowerCase()
                                      const lines = logsText.split('\n')
                                      return lines
                                        .filter((line: string) =>
                                          line
                                            .toLowerCase()
                                            .includes(searchLower),
                                        )
                                        .join('\n')
                                    })()}
                                  </pre>
                                </div>
                              </ScrollArea>
                            </div>
                          </div>
                        ) : (
                          <div className="rounded-lg border border-border bg-card p-3">
                            <code className="text-[13px] text-muted-foreground">
                              No logs found.
                            </code>
                          </div>
                        )}
                      </div>
                      </TabsContent>

                      <TabsContent value="errors" className="mt-0">
                      <div className="mt-4">
                        {func?.logging === false ? (
                          <div className="rounded-lg border border-amber-500/20 bg-amber-500/5 p-4">
                            <p className="text-[13px] text-foreground mb-2">
                              Logging is disabled for this function. Enable
                              logging in settings to view execution errors.
                            </p>
                            <a
                              href="https://appwrite.io/docs"
                              target="_blank"
                              rel="noopener noreferrer"
                              className="text-[12px] text-primary hover:underline"
                            >
                              Learn more →
                            </a>
                          </div>
                        ) : execution.errors ? (
                          <div className="space-y-3">
                            <div className="relative -mx-1 px-1">
                              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                              <Input
                                placeholder="Search errors..."
                                value={errorsSearch}
                                onChange={(e) =>
                                  setErrorsSearch(e.target.value)
                                }
                                className="pl-9 h-9 text-[13px]"
                              />
                            </div>
                            <ScrollArea className="h-[400px] w-full rounded-lg border border-border bg-muted">
                              <div className="p-4 min-w-0">
                                <pre className="text-[12px] font-mono text-foreground whitespace-pre-wrap break-all overflow-x-auto max-w-full min-w-0">
                                  {(() => {
                                    const errorsText =
                                      typeof execution.errors === 'string'
                                        ? execution.errors
                                        : Array.isArray(execution.errors)
                                          ? (execution.errors as string[]).join(
                                              '\n',
                                            )
                                          : JSON.stringify(
                                              execution.errors,
                                              null,
                                              2,
                                            )

                                    if (!errorsSearch.trim()) return errorsText

                                    const searchLower =
                                      errorsSearch.toLowerCase()
                                    const lines = errorsText.split('\n')
                                    return lines
                                      .filter((line: string) =>
                                        line
                                          .toLowerCase()
                                          .includes(searchLower),
                                      )
                                      .join('\n')
                                  })()}
                                </pre>
                              </div>
                            </ScrollArea>
                          </div>
                        ) : (
                          <div className="rounded-lg border border-border bg-card p-3">
                            <code className="text-[13px] text-muted-foreground">
                              No errors found.
                            </code>
                          </div>
                        )}
                      </div>
                      </TabsContent>

                      <TabsContent value="headers" className="mt-0">
                      <div className="mt-4">
                        {execution.responseHeaders &&
                        execution.responseHeaders.length > 0 ? (
                          <>
                            <div className="rounded-lg border border-border overflow-hidden">
                              <Table>
                                <TableHeader>
                                  <TableRow className="hover:bg-transparent border-b border-border">
                                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[200px]">
                                      Key
                                    </TableHead>
                                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                                      Value
                                    </TableHead>
                                  </TableRow>
                                </TableHeader>
                                <TableBody>
                                  {execution.responseHeaders.map(
                                    (header, index) => (
                                      <TableRow key={index}>
                                        <TableCell className="px-4 py-3 font-mono text-[13px]">
                                          {header.name}
                                        </TableCell>
                                        <TableCell className="px-4 py-3 font-mono text-[13px]">
                                          {header.value}
                                        </TableCell>
                                      </TableRow>
                                    ),
                                  )}
                                </TableBody>
                              </Table>
                            </div>
                            <p className="text-[12px] text-muted-foreground mt-4">
                              Missing headers?{' '}
                              <a
                                href="https://appwrite.io/docs"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-primary hover:underline"
                              >
                                Check the docs
                              </a>{' '}
                              to see the supported data and how to log it.
                            </p>
                          </>
                        ) : (
                          <div className="rounded-lg border border-border bg-card p-3">
                            <code className="text-[13px] text-muted-foreground">
                              No headers found.
                            </code>
                          </div>
                        )}
                      </div>
                      </TabsContent>

                      <TabsContent value="body" className="mt-0">
                      <div className="mt-4">
                        {execution.responseBody ? (
                          <div className="space-y-3">
                            <div className="relative -mx-1 px-1">
                              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                              <Input
                                placeholder="Search body..."
                                value={bodySearch}
                                onChange={(e) => setBodySearch(e.target.value)}
                                className="pl-9 h-9 text-[13px]"
                              />
                            </div>
                            <ScrollArea className="h-[400px] w-full rounded-lg border border-border bg-muted">
                              <div className="p-4 min-w-0">
                                <pre className="text-[12px] font-mono text-foreground whitespace-pre-wrap break-all overflow-x-auto max-w-full min-w-0">
                                  {(() => {
                                    const bodyText =
                                      typeof execution.responseBody === 'string'
                                        ? execution.responseBody
                                        : JSON.stringify(
                                            execution.responseBody,
                                            null,
                                            2,
                                          )

                                    if (!bodySearch.trim()) return bodyText

                                    const searchLower = bodySearch.toLowerCase()
                                    const lines = bodyText.split('\n')
                                    return lines
                                      .filter((line) =>
                                        line
                                          .toLowerCase()
                                          .includes(searchLower),
                                      )
                                      .join('\n')
                                  })()}
                                </pre>
                              </div>
                            </ScrollArea>
                          </div>
                        ) : (
                          <div className="rounded-lg border border-border bg-card p-3">
                            <p className="text-[13px] text-foreground">
                              Body data is not captured by Appwrite for your
                              user's security and privacy. To display body data
                              in the Logs tab, use{' '}
                              <code className="px-1.5 py-0.5 bg-muted rounded text-[12px]">
                                context.log()
                              </code>
                              .{' '}
                              <a
                                href="https://appwrite.io/docs"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-primary hover:underline"
                              >
                                Learn more
                              </a>
                              .
                            </p>
                          </div>
                        )}
                      </div>
                      </TabsContent>
                    </Tabs>
                  </div>
                </AccordionContent>
              </AccordionItem>
            </Accordion>
          </div>
        </ScrollArea>
      </>
    </BaseDrawer>
  )
}
