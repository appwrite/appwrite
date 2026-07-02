import { useState, useEffect, useMemo } from 'react'
import {
  ChevronUp,
  ChevronDown,
  Copy,
  Check,
  Search,
  Link2,
  FileJson,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { FixWithAgentDropdown } from '@/components/global/shared/FixWithAgentDropdown'
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
import { formatIpForDisplay } from '@/lib/format-ip'
import { getExecutionStatusBadge, getStatusCodeBadge } from './Executions'
import {
  fetchFunctionExecution,
  fetchSiteLog,
} from '@/lib/react-query/hooks'
import { copyResourceAsJson } from '@/lib/utils/context-menu'
import { generateExecutionAIFixPrompt } from '@/lib/execution-ai-fix-prompt'
import type { ExecutionRowContextMenuVariant } from '@/components/global/shared/ExecutionRowContextMenu'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'

interface ExecutionDetailsDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  execution: Models.Execution | null
  executions: Models.Execution[]
  func: Models.Function | null
  onNavigate: (executionId: string) => void
  projectId?: string
  resourceVariant?: ExecutionRowContextMenuVariant
  resourceId?: string
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

type ExecutionRequestContextFields = {
  requestHost?: string
  clientIp?: string
  hostname?: string
  ip?: string
}

function normalizeOptionalString(value: unknown): string | undefined {
  if (typeof value !== 'string') return undefined
  const trimmed = value.trim()
  return trimmed || undefined
}

function normalizeHeaderValue(value: unknown): string | undefined {
  if (value == null) return undefined
  if (typeof value === 'string') return normalizeOptionalString(value)
  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value)
  }
  if (Array.isArray(value)) {
    for (const item of value) {
      const normalized = normalizeHeaderValue(item)
      if (normalized) return normalized
    }
  }
  return undefined
}

function getRequestHeaderValue(
  headers: Models.Headers[] | undefined,
  name: string,
): string | undefined {
  if (!headers?.length) return undefined
  const target = name.toLowerCase()

  for (const header of headers) {
    if (!header || typeof header !== 'object') continue

    if (
      typeof header.name === 'string' &&
      header.name.toLowerCase() === target
    ) {
      return normalizeHeaderValue(header.value)
    }

    for (const [key, value] of Object.entries(header)) {
      if (key === 'name' || key === 'value') continue
      if (key.toLowerCase() === target) {
        return normalizeHeaderValue(value)
      }
    }
  }

  return undefined
}

function getExecutionHostname(execution: Models.Execution): string | undefined {
  const ext = execution as Models.Execution & ExecutionRequestContextFields
  return (
    normalizeOptionalString(ext.requestHost) ||
    normalizeOptionalString(ext.hostname) ||
    getRequestHeaderValue(execution.requestHeaders, 'host')
  )
}

function getExecutionClientIp(execution: Models.Execution): string | undefined {
  const ext = execution as Models.Execution & ExecutionRequestContextFields
  const fromField =
    normalizeOptionalString(ext.clientIp) ||
    normalizeOptionalString(ext.ip)
  if (fromField) return fromField

  const appwriteIp = getRequestHeaderValue(
    execution.requestHeaders,
    'x-appwrite-client-ip',
  )
  if (appwriteIp) return appwriteIp

  const forwardedFor = getRequestHeaderValue(
    execution.requestHeaders,
    'x-forwarded-for',
  )
  if (forwardedFor) {
    const first = forwardedFor.split(',')[0]?.trim()
    if (first) return first
  }

  return getRequestHeaderValue(execution.requestHeaders, 'x-real-ip')
}

function formatExecutionLogContent(
  logs: Models.Execution['logs'],
): string {
  if (!logs) return ''
  if (typeof logs === 'string') return logs
  if (Array.isArray(logs)) return (logs as string[]).join('\n')
  return JSON.stringify(logs, null, 2)
}

function filterLogLines(text: string, search: string): string {
  if (!search.trim()) return text
  const searchLower = search.toLowerCase()
  return text
    .split('\n')
    .filter((line) => line.toLowerCase().includes(searchLower))
    .join('\n')
}

export function ExecutionDetailsDrawer({
  open,
  onOpenChange,
  execution,
  executions,
  func,
  onNavigate,
  projectId,
  resourceVariant,
  resourceId,
}: ExecutionDetailsDrawerProps) {
  const t = useT()
  const [copiedPath, setCopiedPath] = useState(false)
  const [copiedLink, setCopiedLink] = useState(false)
  const [copiedJson, setCopiedJson] = useState(false)
  const [copiedLogs, setCopiedLogs] = useState(false)
  const [copiedErrors, setCopiedErrors] = useState(false)
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

  // Determine initial response tab when switching executions (avoid [execution] -
  // parent often passes a new object reference each render and would retrigger this
  // effect repeatedly, contributing to nested update limits).
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
    setCopiedLogs(false)
    setCopiedErrors(false)
  }, [execution?.$id])

  const requestHeaderCount = execution?.requestHeaders?.length ?? 0

  // Set initial request tab
  useEffect(() => {
    if (queryParams.length > 0) {
      setRequestTab('parameters')
    } else if (requestHeaderCount > 0) {
      setRequestTab('headers')
    }
  }, [queryParams.length, execution?.$id, requestHeaderCount])

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
  }, [execution?.$id, execution?.$createdAt, execution?.status])

  const executionLogsText = useMemo(
    () => formatExecutionLogContent(execution?.logs),
    [execution?.logs],
  )

  const displayedLogsText = useMemo(
    () => filterLogLines(executionLogsText, logsSearch),
    [executionLogsText, logsSearch],
  )

  const executionErrorsText = useMemo(
    () => formatExecutionLogContent(execution?.errors),
    [execution?.errors],
  )

  const displayedErrorsText = useMemo(
    () => filterLogLines(executionErrorsText, errorsSearch),
    [executionErrorsText, errorsSearch],
  )

  const showFixWithAgent = (execution?.responseStatusCode ?? 0) >= 400

  const aiFixPrompt = useMemo(() => {
    if (!execution || !showFixWithAgent) return ''
    return generateExecutionAIFixPrompt(execution, {
      resourceVariant,
      resourceName: func?.name,
      runtime: func?.runtime,
    })
  }, [
    execution,
    showFixWithAgent,
    resourceVariant,
    func?.name,
    func?.runtime,
  ])

  if (!execution) return null

  const statusBadge = getExecutionStatusBadge(execution.status)
  const statusCodeBadge = execution.responseStatusCode
    ? getStatusCodeBadge(execution.responseStatusCode)
    : null
  const requestHostname = getExecutionHostname(execution)
  const clientIp = getExecutionClientIp(execution)
  const clientIpDisplay = clientIp
    ? (formatIpForDisplay(clientIp, 44) ?? clientIp)
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

  const handleCopyJson = async () => {
    if (!execution) return

    const resolvedProjectId = projectId
    const resolvedResourceId =
      resourceId ?? (func ? func.$id : undefined)
    const resolvedVariant =
      resourceVariant ?? (func ? ('function' as const) : undefined)

    const copied = await copyResourceAsJson(
      () => {
        if (
          resolvedProjectId &&
          resolvedResourceId &&
          resolvedVariant === 'function'
        ) {
          return fetchFunctionExecution(
            resolvedProjectId,
            resolvedResourceId,
            execution.$id,
          )
        }
        if (
          resolvedProjectId &&
          resolvedResourceId &&
          resolvedVariant === 'site'
        ) {
          return fetchSiteLog(
            resolvedProjectId,
            resolvedResourceId,
            execution.$id,
          )
        }
        return execution
      },
      { fallback: execution },
    )

    if (copied) {
      setCopiedJson(true)
      setTimeout(() => setCopiedJson(false), 2000)
    }
  }

  const handleCopyLogs = () => {
    if (!executionLogsText) return
    navigator.clipboard.writeText(executionLogsText)
    setCopiedLogs(true)
    setTimeout(() => setCopiedLogs(false), 2000)
  }

  const handleCopyErrors = () => {
    if (!executionErrorsText) return
    navigator.clipboard.writeText(executionErrorsText)
    setCopiedErrors(true)
    setTimeout(() => setCopiedErrors(false), 2000)
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
      headerLeading={
        <div className="flex min-w-0 flex-wrap items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            className="h-8 shrink-0 text-[13px]"
            onClick={() => void handleCopyJson()}
          >
            {copiedJson ? (
              <Check className="me-1.5 h-4 w-4 text-emerald-500" />
            ) : (
              <FileJson className="me-1.5 h-4 w-4" />
            )}
            {t('Copy as JSON')}
          </Button>
          {showFixWithAgent && (
            <FixWithAgentDropdown
              prompt={aiFixPrompt}
              align="start"
              className="h-8 shrink-0 text-[13px]"
            />
          )}
        </div>
      }
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
                <p>{copiedLink ? t('Link copied!') : t('Copy link')}</p>
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
                  {t('Details')}
                </AccordionTrigger>
                <AccordionContent className="pt-4 overflow-visible">
                  <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 -mx-1.5 px-1.5">
                    {/* Execution ID */}
                    <div>
                      <p className="text-[14px] text-muted-foreground mb-2">
                        {t('Execution ID')}
                      </p>
                      <CopyableId id={execution.$id} size="sm" />
                    </div>

                    {/* Deployment ID */}
                    {execution.deploymentId && (
                      <div>
                        <p className="text-[14px] text-muted-foreground mb-2">
                          {t('Deployment ID')}
                        </p>
                        <CopyableId id={execution.deploymentId} size="sm" />
                      </div>
                    )}

                    {/* Method */}
                    <div>
                      <p className="text-[14px] text-muted-foreground mb-2">
                        {t('Method')}
                      </p>
                      <p className="text-[14px] text-foreground font-medium">
                        {execution.requestMethod?.toUpperCase() || 'N/A'}
                      </p>
                    </div>

                    {/* Hostname */}
                    <div>
                      <p className="text-[14px] text-muted-foreground mb-2">
                        {t('Hostname')}
                      </p>
                      {requestHostname ? (
                        <CopyableId
                          id={requestHostname}
                          size="sm"
                          maxWidth={280}
                        />
                      ) : (
                        <span className="text-[14px] text-muted-foreground">
                          N/A
                        </span>
                      )}
                    </div>

                    {/* IP address */}
                    <div>
                      <p className="text-[14px] text-muted-foreground mb-2">
                        {t('IP address')}
                      </p>
                      {clientIp ? (
                        <CopyableId
                          id={clientIp}
                          displayText={clientIpDisplay ?? clientIp}
                          size="sm"
                          maxWidth={360}
                        />
                      ) : (
                        <span className="text-[14px] text-muted-foreground">
                          N/A
                        </span>
                      )}
                    </div>

                    {/* Status Code */}
                    <div>
                      <p className="text-[14px] text-muted-foreground mb-2">
                        {t('Status code')}
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
                        {t('Status')}
                      </p>
                      <Badge variant={statusBadge.variant}>
                        {t(statusBadge.label)}
                      </Badge>
                    </div>

                    {/* Triggered By */}
                    <div>
                      <p className="text-[14px] text-muted-foreground mb-2">
                        {t('Triggered by')}
                      </p>
                      <p className="text-[14px] text-foreground">
                        {execution.trigger
                          ? t(capitalizeFirst(execution.trigger))
                          : 'N/A'}
                      </p>
                    </div>

                    {/* Duration */}
                    <div>
                      <p className="text-[14px] text-muted-foreground mb-2">
                        {t('Duration')}
                      </p>
                      <p className="text-[14px] text-foreground font-mono">
                        {durationDisplay}
                      </p>
                    </div>

                    {/* Created */}
                    <div>
                      <p className="text-[14px] text-muted-foreground mb-2">
                        {t('Created')}
                      </p>
                      <DateTooltip date={execution.$createdAt} />
                    </div>

                    {/* Path - Full Width */}
                    <div className="sm:col-span-2 lg:col-span-3">
                      <p className="text-[14px] text-muted-foreground mb-2">
                        {t('Path')}
                      </p>
                      {execution.requestPath ? (
                        <div className="relative -mx-1 px-1">
                          <Input
                            value={execution.requestPath}
                            readOnly
                            className="pe-10 font-mono text-[13px] bg-muted"
                          />
                          <button
                            type="button"
                            onClick={handleCopyPath}
                            className="absolute end-2 top-1/2 -translate-y-1/2 flex items-center justify-center h-7 w-7 rounded-md hover:bg-accent transition-colors"
                            aria-label={t('Copy path')}
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
                  {t('Request')}
                </AccordionTrigger>
                <AccordionContent className="pt-4 overflow-visible">
                  <div className="-mx-1.5 px-1.5">
                    <Tabs
                      value={requestTab}
                      onValueChange={(v) =>
                        setRequestTab(v as 'parameters' | 'headers')
                      }
                      className="w-full gap-4"
                    >
                      <TabsList className="w-full grid grid-cols-2 h-9">
                        <TabsTrigger value="parameters" className="text-[13px]">
                          {t('Parameters')}
                          {queryParams.length > 0 && (
                            <span className="ms-1.5 text-muted-foreground">
                              ({queryParams.length})
                            </span>
                          )}
                        </TabsTrigger>
                        <TabsTrigger value="headers" className="text-[13px]">
                          {t('Headers')}
                          {execution.requestHeaders &&
                            execution.requestHeaders.length > 0 && (
                              <span className="ms-1.5 text-muted-foreground">
                                ({execution.requestHeaders.length})
                              </span>
                            )}
                        </TabsTrigger>
                      </TabsList>

                      <TabsContent value="parameters" className="mt-0">
                        <div>
                          {queryParams.length > 0 ? (
                            <div className="rounded-lg border border-border overflow-hidden">
                              <Table className="table-fixed w-full">
                                <TableHeader>
                                  <TableRow className="hover:bg-transparent border-b border-border">
                                    <TableHead className="w-[35%] px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                                      {t('Key')}
                                    </TableHead>
                                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                                      {t('Value')}
                                    </TableHead>
                                  </TableRow>
                                </TableHeader>
                                <TableBody>
                                  {queryParams.map((param, index) => (
                                    <TableRow key={index}>
                                      <TableCell className="px-4 py-3 align-top font-mono text-[13px] break-all whitespace-normal">
                                        {param.name}
                                      </TableCell>
                                      <TableCell className="min-w-0 px-4 py-3 align-top font-mono text-[13px] break-all whitespace-normal">
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
                                {t('No parameters found.')}
                              </code>
                            </div>
                          )}
                        </div>
                      </TabsContent>

                      <TabsContent value="headers" className="mt-0">
                        <div>
                          {execution.requestHeaders &&
                          execution.requestHeaders.length > 0 ? (
                            <>
                              <div className="rounded-lg border border-border overflow-hidden">
                                <Table className="table-fixed w-full">
                                  <TableHeader>
                                    <TableRow className="hover:bg-transparent border-b border-border">
                                      <TableHead className="w-[35%] px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                                        {t('Key')}
                                      </TableHead>
                                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                                        {t('Value')}
                                      </TableHead>
                                    </TableRow>
                                  </TableHeader>
                                  <TableBody>
                                    {execution.requestHeaders.map(
                                      (header, index) => (
                                        <TableRow key={index}>
                                          <TableCell className="px-4 py-3 align-top font-mono text-[13px] break-all whitespace-normal">
                                            {header.name}
                                          </TableCell>
                                          <TableCell className="min-w-0 px-4 py-3 align-top font-mono text-[13px] break-all whitespace-normal">
                                            {header.value}
                                          </TableCell>
                                        </TableRow>
                                      ),
                                    )}
                                  </TableBody>
                                </Table>
                              </div>
                              <p className="text-[12px] text-muted-foreground mt-4">
                                {t('Missing headers?')}{' '}
                                <DocsRouteLink className="link-neutral" href="/docs">
                                  {t('Check the docs')}
                                </DocsRouteLink>{' '}
                                {t('to see the supported data and how to log it.')}
                              </p>
                            </>
                          ) : (
                            <div className="rounded-lg border border-border bg-card p-3">
                              <code className="text-[13px] text-muted-foreground">
                                {t('No headers found.')}
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
                  {t('Response')}
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
                      className="w-full gap-4"
                    >
                      <TabsList className="w-full grid grid-cols-4 h-9">
                        <TabsTrigger value="logs" className="text-[13px]">
                          {t('Logs')}
                        </TabsTrigger>
                        <TabsTrigger value="errors" className="text-[13px]">
                          {t('Errors')}
                        </TabsTrigger>
                        <TabsTrigger value="headers" className="text-[13px]">
                          {t('Headers')}
                          {execution.responseHeaders &&
                            execution.responseHeaders.length > 0 && (
                              <span className="ms-1.5 text-muted-foreground">
                                ({execution.responseHeaders.length})
                              </span>
                            )}
                        </TabsTrigger>
                        <TabsTrigger value="body" className="text-[13px]">
                          {t('Body')}
                        </TabsTrigger>
                      </TabsList>

                      <TabsContent value="logs" className="mt-0">
                        <div>
                          {func?.logging === false ? (
                            <div className="rounded-lg border border-amber-500/20 bg-amber-500/5 p-4">
                              <p className="text-[13px] text-foreground mb-2">
                                {t(
                                  'Logging is disabled for this function. Enable logging in settings to view execution logs.',
                                )}
                              </p>
                              <DocsRouteLink className="link-neutral text-[12px]" href="/docs">
                                {t('Learn more')} →
                              </DocsRouteLink>
                            </div>
                          ) : execution.logs ? (
                            <div className="rounded-lg border border-border bg-card p-4">
                              <div className="space-y-3">
                                <div className="flex items-center gap-2">
                                  <div className="relative flex-1 min-w-0 -mx-1 px-1">
                                    <Search className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                                    <Input
                                      placeholder={t('Search logs...')}
                                      value={logsSearch}
                                      onChange={(e) =>
                                        setLogsSearch(e.target.value)
                                      }
                                      className="ps-9 h-9 text-[13px]"
                                    />
                                  </div>
                                  <TooltipProvider>
                                    <Tooltip>
                                      <TooltipTrigger asChild>
                                        <Button
                                          type="button"
                                          variant="outline"
                                          size="sm"
                                          onClick={handleCopyLogs}
                                          disabled={!executionLogsText}
                                          className="h-9 w-9 shrink-0 p-0"
                                          aria-label={t('Copy logs')}
                                        >
                                          {copiedLogs ? (
                                            <Check className="h-4 w-4 text-emerald-500" />
                                          ) : (
                                            <Copy className="h-4 w-4" />
                                          )}
                                        </Button>
                                      </TooltipTrigger>
                                      <TooltipContent>
                                        <p>{t('Copy logs')}</p>
                                      </TooltipContent>
                                    </Tooltip>
                                  </TooltipProvider>
                                </div>
                                <ScrollArea className="h-[400px] w-full rounded-lg border border-border">
                                  <div className="p-4 min-w-0">
                                    <pre className="text-[12px] font-mono text-foreground whitespace-pre-wrap break-all overflow-x-auto max-w-full min-w-0">
                                      {displayedLogsText}
                                    </pre>
                                  </div>
                                </ScrollArea>
                              </div>
                            </div>
                          ) : (
                            <div className="rounded-lg border border-border bg-card p-3">
                              <code className="text-[13px] text-muted-foreground">
                                {t('No logs found.')}
                              </code>
                            </div>
                          )}
                        </div>
                      </TabsContent>

                      <TabsContent value="errors" className="mt-0">
                        <div>
                          {func?.logging === false ? (
                            <div className="rounded-lg border border-amber-500/20 bg-amber-500/5 p-4">
                              <p className="text-[13px] text-foreground mb-2">
                                {t(
                                  'Logging is disabled for this function. Enable logging in settings to view execution errors.',
                                )}
                              </p>
                              <DocsRouteLink className="link-neutral text-[12px]" href="/docs">
                                {t('Learn more')} →
                              </DocsRouteLink>
                            </div>
                          ) : execution.errors ? (
                            <div className="rounded-lg border border-border bg-card p-4">
                              <div className="space-y-3">
                                <div className="flex items-center gap-2">
                                  <div className="relative min-w-0 flex-1 -mx-1 px-1">
                                    <Search className="absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                      placeholder={t('Search errors...')}
                                      value={errorsSearch}
                                      onChange={(e) =>
                                        setErrorsSearch(e.target.value)
                                      }
                                      className="h-9 ps-9 text-[13px]"
                                    />
                                  </div>
                                  <TooltipProvider>
                                    <Tooltip>
                                      <TooltipTrigger asChild>
                                        <Button
                                          type="button"
                                          variant="outline"
                                          size="sm"
                                          onClick={handleCopyErrors}
                                          disabled={!executionErrorsText}
                                          className="h-9 w-9 shrink-0 p-0"
                                          aria-label={t('Copy errors')}
                                        >
                                          {copiedErrors ? (
                                            <Check className="h-4 w-4 text-emerald-500" />
                                          ) : (
                                            <Copy className="h-4 w-4" />
                                          )}
                                        </Button>
                                      </TooltipTrigger>
                                      <TooltipContent>
                                        <p>{t('Copy errors')}</p>
                                      </TooltipContent>
                                    </Tooltip>
                                  </TooltipProvider>
                                </div>
                                <ScrollArea className="h-[400px] w-full rounded-lg border border-border bg-muted">
                                  <div className="min-w-0 p-4">
                                    <pre className="max-w-full min-w-0 overflow-x-auto whitespace-pre-wrap break-all font-mono text-[12px] text-foreground">
                                      {displayedErrorsText}
                                    </pre>
                                  </div>
                                </ScrollArea>
                              </div>
                            </div>
                          ) : (
                            <div className="rounded-lg border border-border bg-card p-3">
                              <code className="text-[13px] text-muted-foreground">
                                {t('No errors found.')}
                              </code>
                            </div>
                          )}
                        </div>
                      </TabsContent>

                      <TabsContent value="headers" className="mt-0">
                        <div>
                          {execution.responseHeaders &&
                          execution.responseHeaders.length > 0 ? (
                            <>
                              <div className="rounded-lg border border-border overflow-hidden">
                                <Table className="table-fixed w-full">
                                  <TableHeader>
                                    <TableRow className="hover:bg-transparent border-b border-border">
                                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[200px]">
                                        {t('Key')}
                                      </TableHead>
                                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                                        {t('Value')}
                                      </TableHead>
                                    </TableRow>
                                  </TableHeader>
                                  <TableBody>
                                    {execution.responseHeaders.map(
                                      (header, index) => (
                                        <TableRow key={index}>
                                          <TableCell className="px-4 py-3 align-top font-mono text-[13px] break-all whitespace-normal">
                                            {header.name}
                                          </TableCell>
                                          <TableCell className="min-w-0 px-4 py-3 align-top font-mono text-[13px] break-all whitespace-normal">
                                            {header.value}
                                          </TableCell>
                                        </TableRow>
                                      ),
                                    )}
                                  </TableBody>
                                </Table>
                              </div>
                              <p className="text-[12px] text-muted-foreground mt-4">
                                {t('Missing headers?')}{' '}
                                <DocsRouteLink className="link-neutral" href="/docs">
                                  {t('Check the docs')}
                                </DocsRouteLink>{' '}
                                {t('to see the supported data and how to log it.')}
                              </p>
                            </>
                          ) : (
                            <div className="rounded-lg border border-border bg-card p-3">
                              <code className="text-[13px] text-muted-foreground">
                                {t('No headers found.')}
                              </code>
                            </div>
                          )}
                        </div>
                      </TabsContent>

                      <TabsContent value="body" className="mt-0">
                        <div>
                          {execution.responseBody ? (
                            <div className="space-y-3">
                              <div className="relative -mx-1 px-1">
                                <Search className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                                <Input
                                  placeholder={t('Search body...')}
                                  value={bodySearch}
                                  onChange={(e) =>
                                    setBodySearch(e.target.value)
                                  }
                                  className="ps-9 h-9 text-[13px]"
                                />
                              </div>
                              <ScrollArea className="h-[400px] w-full rounded-lg border border-border bg-muted">
                                <div className="p-4 min-w-0">
                                  <pre className="text-[12px] font-mono text-foreground whitespace-pre-wrap break-all overflow-x-auto max-w-full min-w-0">
                                    {(() => {
                                      const bodyText =
                                        typeof execution.responseBody ===
                                        'string'
                                          ? execution.responseBody
                                          : JSON.stringify(
                                              execution.responseBody,
                                              null,
                                              2,
                                            )

                                      if (!bodySearch.trim()) return bodyText

                                      const searchLower =
                                        bodySearch.toLowerCase()
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
                                {t(
                                  "Body data is not captured by Appwrite for your user's security and privacy. To display body data in the Logs tab, use", // pragma: allowlist secret
                                )}{' '}
                                <code className="px-1.5 py-0.5 bg-muted rounded text-[12px]">
                                  context.log()
                                </code>
                                .{' '}
                                <DocsRouteLink className="link-neutral" href="/docs">
                                  {t('Learn more')}
                                </DocsRouteLink>
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
