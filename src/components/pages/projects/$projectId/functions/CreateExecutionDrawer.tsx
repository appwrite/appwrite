import { useState, useEffect, useMemo } from 'react'
import { useParams } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { Play, Plus, X } from 'lucide-react'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Badge } from '@/components/ui/badge'
import { useFunctionDeploymentProxyRules } from '@/lib/react-query/hooks'
import { ExecutionMethod } from '@appwrite.io/console'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'

const HTTP_METHODS = [
  { value: ExecutionMethod.GET, label: 'GET' },
  { value: ExecutionMethod.POST, label: 'POST' },
  { value: ExecutionMethod.PUT, label: 'PUT' },
  { value: ExecutionMethod.PATCH, label: 'PATCH' },
  { value: ExecutionMethod.DELETE, label: 'DELETE' },
]

const SUGGESTED_HEADER_KEYS = [
  'Content-Type',
  'Accept',
  'Accept-Language',
  'Authorization',
  'X-API-Key',
  'User-Agent',
  'Cache-Control',
  'X-Requested-With',
  'Origin',
]

interface HeaderRow {
  id: string
  key: string
  value: string
}

function nextHeaderId() {
  return `h-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`
}

interface HttpResponse {
  status: number
  statusText: string
  headers: Array<{ name: string; value: string }>
  body: string
  ok: boolean
}

interface CreateExecutionDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  functionId: string
  func: Models.Function | null
  onSuccess?: (executionId: string) => void
}

export function CreateExecutionDrawer({
  open,
  onOpenChange,
  functionId,
  func,
  onSuccess,
}: CreateExecutionDrawerProps) {
  const { projectId } = useParams({ strict: false })
  const queryClient = useQueryClient()

  const { rules: proxyRules } = useFunctionDeploymentProxyRules(
    projectId,
    functionId,
    func?.deploymentId ?? undefined,
  )

  const hostnames = useMemo(
    () => proxyRules.filter((r) => r.domain).map((r) => r.domain as string),
    [proxyRules],
  )

  const [hostname, setHostname] = useState('')
  const [method, setMethod] = useState<ExecutionMethod>(ExecutionMethod.POST)
  const [path, setPath] = useState('/')
  const DEFAULT_BODY = '{}'
  const [body, setBody] = useState(DEFAULT_BODY)
  const [headers, setHeaders] = useState<HeaderRow[]>([])
  const [isExecuting, setIsExecuting] = useState(false)
  const [response, setResponse] = useState<HttpResponse | null>(null)

  useEffect(() => {
    if (!open) return
    setMethod(ExecutionMethod.POST)
    setPath('/')
    setBody(DEFAULT_BODY)
    setHeaders([])
    setResponse(null)
    if (hostnames.length > 0) {
      setHostname(hostnames[0])
    }
  }, [open, hostnames])

  useEffect(() => {
    if (hostnames.length > 0 && !hostname) {
      setHostname(hostnames[0])
    }
  }, [hostnames, hostname])

  const executeRequest = async (): Promise<HttpResponse> => {
    const pathNormalized = path.startsWith('/') ? path : `/${path}`
    const url = `https://${hostname}${pathNormalized}`

    const headersObj: Record<string, string> = {}
    for (const row of headers) {
      const key = row.key.trim()
      if (key && row.value.trim()) {
        headersObj[key] = row.value.trim()
      }
    }

    const init: RequestInit = {
      method,
      headers: Object.keys(headersObj).length > 0 ? headersObj : undefined,
    }
    if (
      body.trim() &&
      [
        ExecutionMethod.POST,
        ExecutionMethod.PUT,
        ExecutionMethod.PATCH,
      ].includes(method)
    ) {
      init.body = body.trim()
    }

    const res = await fetch(url, init)
    const headerList: Array<{ name: string; value: string }> = []
    res.headers.forEach((value, name) => {
      headerList.push({ name, value })
    })
    const bodyText = await res.text()

    return {
      status: res.status,
      statusText: res.statusText,
      headers: headerList,
      body: bodyText,
      ok: res.ok,
    }
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!hostname) {
      toast.error('Select a hostname')
      return
    }
    setIsExecuting(true)
    setResponse(null)
    try {
      const res = await executeRequest()
      setResponse(res)
      if (res.ok) {
        queryClient.refetchQueries({
          queryKey: ['executions', 'function', projectId, functionId],
        })
        if (onSuccess) {
          onSuccess('')
        }
      }
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Request failed'
      const isCorsOrNetwork =
        message === 'Failed to fetch' ||
        message.includes('NetworkError') ||
        message.includes('Load failed')
      const bodyMessage = isCorsOrNetwork
        ? 'The response could not be read (often due to CORS when the server returns an error). Check the browser Network tab for the actual status code (e.g. 403) and response body.'
        : message
      setResponse({
        status: 0,
        statusText: isCorsOrNetwork
          ? 'Response blocked (see Network tab)'
          : 'Error',
        headers: [],
        body: bodyMessage,
        ok: false,
      })
    } finally {
      setIsExecuting(false)
    }
  }

  const handleOpenChange = (next: boolean) => {
    if (!next) {
      setMethod(ExecutionMethod.POST)
      setPath('/')
      setBody(DEFAULT_BODY)
      setHeaders([])
      setResponse(null)
    }
    onOpenChange(next)
  }

  const addHeader = () => {
    setHeaders((prev) => [...prev, { id: nextHeaderId(), key: '', value: '' }])
  }

  const updateHeader = (id: string, updates: Partial<HeaderRow>) => {
    setHeaders((prev) =>
      prev.map((h) => (h.id === id ? { ...h, ...updates } : h)),
    )
  }

  const removeHeader = (id: string) => {
    setHeaders((prev) => prev.filter((h) => h.id !== id))
  }

  const canExecute =
    func?.deploymentId != null && hostnames.length > 0 && hostname

  const formatResponseBody = (text: string): string => {
    try {
      const parsed = JSON.parse(text)
      return JSON.stringify(parsed, null, 2)
    } catch {
      return text
    }
  }

  return (
    <BaseDrawer
      open={open}
      onOpenChange={handleOpenChange}
      title="Create execution"
      maxWidth="sm:max-w-lg"
    >
      <>
        <div className="border-t border-border shrink-0" />

        <form onSubmit={handleSubmit} className="flex flex-col flex-1 min-h-0">
          <fieldset
            disabled={hostnames.length === 0}
            className="flex flex-col flex-1 min-h-0 border-0 p-0 m-0 min-w-0"
          >
            <div className="flex flex-col flex-1 min-h-0">
              <div className="flex-1 overflow-y-auto min-h-0">
                <div className="px-6 py-6 space-y-6">
                  {!func?.deploymentId && (
                    <p className="text-[13px] text-amber-600 dark:text-amber-400">
                      No active deployment. Deploy the function first to run
                      executions.
                    </p>
                  )}
                  {func?.deploymentId && hostnames.length === 0 && (
                    <p className="text-[13px] text-amber-600 dark:text-amber-400">
                      No domains configured for this deployment. Add a domain in
                      the Domains tab.
                    </p>
                  )}

                  <div className="space-y-4">
                    <div className="space-y-2">
                      <Label
                        htmlFor="create-exec-hostname"
                        className="text-[13px]"
                      >
                        Hostname
                      </Label>
                      <Select
                        value={hostname}
                        onValueChange={setHostname}
                        disabled={hostnames.length === 0}
                      >
                        <SelectTrigger
                          id="create-exec-hostname"
                          className="h-9 w-full text-[13px] font-mono"
                        >
                          <SelectValue placeholder="Select hostname" />
                        </SelectTrigger>
                        <SelectContent>
                          {hostnames.map((d) => (
                            <SelectItem key={d} value={d}>
                              {d}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>

                    <div className="grid grid-cols-[7rem_1fr] gap-x-3 gap-y-2 items-end">
                      <div className="space-y-2 min-w-0">
                        <Label
                          htmlFor="create-exec-method"
                          className="text-[13px]"
                        >
                          Method
                        </Label>
                        <Select
                          value={method}
                          onValueChange={(v) => setMethod(v as ExecutionMethod)}
                        >
                          <SelectTrigger
                            id="create-exec-method"
                            className="h-9 text-[13px] w-full min-w-0 mb-0"
                          >
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            {HTTP_METHODS.map((m) => (
                              <SelectItem key={m.value} value={m.value}>
                                {m.label}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </div>
                      <div className="space-y-2 min-w-0">
                        <Label
                          htmlFor="create-exec-path"
                          className="text-[13px]"
                        >
                          Path
                        </Label>
                        <Input
                          id="create-exec-path"
                          value={path}
                          onChange={(e) => setPath(e.target.value)}
                          placeholder="/"
                          className="h-9 text-[13px] font-mono"
                        />
                      </div>
                    </div>

                    <div className="border-t border-border pt-4">
                      <div className="flex items-center gap-2 mb-2">
                        <span className="text-[14px] font-medium">Headers</span>
                        <Badge
                          variant="secondary"
                          className="text-[11px] font-normal"
                        >
                          Optional
                        </Badge>
                      </div>
                      <p className="text-[13px] text-muted-foreground mb-3">
                        Provide essential metadata to define the content type,
                        authentication details, and the expected response
                        format.
                      </p>
                      <div className="space-y-3">
                        <datalist id="header-keys-suggestions">
                          {SUGGESTED_HEADER_KEYS.map((k) => (
                            <option key={k} value={k} />
                          ))}
                        </datalist>
                        {headers.map((row) => (
                          <div
                            key={row.id}
                            className="flex gap-2 items-center flex-wrap"
                          >
                            <Input
                              value={row.key}
                              onChange={(e) =>
                                updateHeader(row.id, { key: e.target.value })
                              }
                              list="header-keys-suggestions"
                              placeholder="Header name"
                              className="h-9 text-[13px] w-[180px] shrink-0"
                            />
                            <Input
                              value={row.value}
                              onChange={(e) =>
                                updateHeader(row.id, { value: e.target.value })
                              }
                              placeholder="Enter value"
                              className="h-9 text-[13px] flex-1 min-w-[120px]"
                            />
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              className="h-9 w-9 p-0 shrink-0 text-muted-foreground hover:text-destructive"
                              onClick={() => removeHeader(row.id)}
                              aria-label="Remove header"
                            >
                              <X className="h-4 w-4" />
                            </Button>
                          </div>
                        ))}
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="h-8 text-[13px] text-primary hover:text-primary"
                          onClick={addHeader}
                        >
                          <Plus className="mr-1.5 h-3.5 w-3.5" />
                          Add header
                        </Button>
                      </div>
                    </div>

                    <div className="border-t border-border pt-4">
                      <div className="flex items-center gap-2 mb-2">
                        <span className="text-[14px] font-medium">Body</span>
                        <Badge
                          variant="secondary"
                          className="text-[11px] font-normal"
                        >
                          Optional
                        </Badge>
                      </div>
                      <p className="text-[13px] text-muted-foreground mb-3">
                        Provide the request body to include the main data you
                        want to send to the server.
                      </p>
                      <Textarea
                        value={body}
                        onChange={(e) => setBody(e.target.value)}
                        placeholder="Enter request body here..."
                        className="min-h-[100px] text-[13px] font-mono resize-y"
                        rows={4}
                      />
                    </div>
                  </div>
                </div>
              </div>
            </div>

            {response && (
              <div className="flex-shrink-0 border-t border-border bg-muted/30 overflow-hidden">
                <div className="px-6 py-3 border-b border-border">
                  <span className="text-[13px] font-medium">Response</span>
                  <Badge
                    variant={response.ok ? 'default' : 'destructive'}
                    className="ml-2 text-[11px]"
                  >
                    {response.status} {response.statusText}
                  </Badge>
                </div>
                <div className="max-h-[200px] overflow-y-auto">
                  <div className="px-6 py-3 border-b border-border">
                    <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground mb-2">
                      Headers
                    </p>
                    <div className="space-y-1">
                      {response.headers.map((h) => (
                        <div
                          key={h.name}
                          className="text-[12px] font-mono flex gap-2"
                        >
                          <span className="text-muted-foreground shrink-0">
                            {h.name}:
                          </span>
                          <span className="break-all">{h.value}</span>
                        </div>
                      ))}
                      {response.headers.length === 0 && (
                        <p className="text-[12px] text-muted-foreground">
                          No headers
                        </p>
                      )}
                    </div>
                  </div>
                  <div className="px-6 py-3">
                    <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground mb-2">
                      Body
                    </p>
                    <pre className="text-[12px] font-mono whitespace-pre-wrap break-words bg-background/50 rounded border border-border p-3 overflow-x-auto">
                      {response.body
                        ? formatResponseBody(response.body)
                        : '(empty)'}
                    </pre>
                  </div>
                </div>
              </div>
            )}
          </fieldset>

          <div className="flex-shrink-0 flex items-center justify-start gap-2 border-t border-border bg-muted/30 px-6 py-4">
            <Button type="submit" disabled={!canExecute || isExecuting}>
              <Play className="mr-1.5 h-4 w-4" />
              Execute
            </Button>
            <Button
              type="button"
              variant="outline"
              onClick={() => handleOpenChange(false)}
            >
              Cancel
            </Button>
          </div>
        </form>
      </>
    </BaseDrawer>
  )
}
