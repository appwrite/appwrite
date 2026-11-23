import { useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { Play, Plus, X } from 'lucide-react'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Badge } from '@/components/ui/badge'
import { Switch } from '@/components/ui/switch'
import { ExecutionMethod } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

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

const DEFAULT_BODY = '{}'

const selectFieldClassName =
  'border-input bg-transparent dark:bg-input/30 flex h-9 w-full min-w-0 rounded-md border px-3 py-1 text-[13px] shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50'

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

function executionToHttpResponse(execution: Models.Execution): HttpResponse {
  const headers = (execution.responseHeaders ?? []).map((h) => ({
    name: h.name,
    value: h.value,
  }))
  const status = execution.responseStatusCode ?? 0
  const ok =
    execution.status === 'completed' && status >= 200 && status < 300
  const body =
    execution.responseBody ||
    execution.errors ||
    (execution.logs ? execution.logs : '')

  return {
    status,
    statusText: execution.status,
    headers,
    body,
    ok,
  }
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
  const t = useT()
  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={t('Create execution')}
      maxWidth="sm:max-w-lg"
      disableAutoFocus
    >
      {open ? (
        <CreateExecutionDrawerForm
          functionId={functionId}
          func={func}
          onOpenChange={onOpenChange}
          onSuccess={onSuccess}
        />
      ) : null}
    </BaseDrawer>
  )
}

interface CreateExecutionDrawerFormProps {
  functionId: string
  func: Models.Function | null
  onOpenChange: (open: boolean) => void
  onSuccess?: (executionId: string) => void
}

function CreateExecutionDrawerForm({
  functionId,
  func,
  onOpenChange,
  onSuccess,
}: CreateExecutionDrawerFormProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const queryClient = useQueryClient()

  const [method, setMethod] = useState<ExecutionMethod>(ExecutionMethod.POST)
  const [path, setPath] = useState('/')
  const [body, setBody] = useState(DEFAULT_BODY)
  const [headers, setHeaders] = useState<HeaderRow[]>([])
  const [isAsync, setIsAsync] = useState(false)
  const [isExecuting, setIsExecuting] = useState(false)
  const [response, setResponse] = useState<HttpResponse | null>(null)

  const canExecute = func?.deploymentId != null
  const fieldsDisabled = !canExecute || isExecuting

  const buildHeadersObject = (): Record<string, string> | undefined => {
    const headersObj: Record<string, string> = {}
    for (const row of headers) {
      const key = row.key.trim()
      if (key) {
        headersObj[key] = row.value.trim()
      }
    }
    return Object.keys(headersObj).length > 0 ? headersObj : undefined
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!projectId) {
      toast.error(t('Project ID is required'))
      return
    }

    setIsExecuting(true)
    setResponse(null)
    try {
      const pathNormalized = path.startsWith('/') ? path : `/${path}`
      const projectSdk = sdk.forProject(projectId)
      const execution = await projectSdk.functions.createExecution({
        functionId,
        xpath: pathNormalized,
        method,
        body:
          body.trim() &&
          [
            ExecutionMethod.POST,
            ExecutionMethod.PUT,
            ExecutionMethod.PATCH,
          ].includes(method)
            ? body.trim()
            : undefined,
        headers: buildHeadersObject(),
        async: isAsync,
      })

      await queryClient.refetchQueries({
        queryKey: ['executions', 'function', projectId, functionId],
      })

      onSuccess?.(execution.$id)

      if (isAsync) {
        toast.success(t('Async execution created'))
        onOpenChange(false)
        return
      }

      const res = executionToHttpResponse(execution)
      setResponse(res)

      if (!res.ok) {
        toast.error(
          execution.errors?.trim() ||
            `${t('Execution finished with status')} ${execution.status}`,
        )
      }
    } catch (err) {
      const message = err instanceof Error ? err.message : t('Request failed')
      setResponse({
        status: 0,
        statusText: 'Error',
        headers: [],
        body: message,
        ok: false,
      })
      toast.error(message)
    } finally {
      setIsExecuting(false)
    }
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

  const formatResponseBody = (text: string): string => {
    try {
      const parsed = JSON.parse(text)
      return JSON.stringify(parsed, null, 2)
    } catch {
      return text
    }
  }

  return (
    <>
      <div className="border-t border-border shrink-0" />

      <form onSubmit={handleSubmit} className="flex flex-col flex-1 min-h-0">
        <div className="flex flex-col flex-1 min-h-0">
          <div className="flex-1 overflow-y-auto min-h-0">
            <div className="px-6 py-6 space-y-6">
              {!func?.deploymentId && (
                <p className="text-[13px] text-amber-600 dark:text-amber-400">
                  {t(
                    'No active deployment. Deploy the function first to run executions.',
                  )}
                </p>
              )}

              <div className="space-y-4">
                <div className="grid grid-cols-[7rem_1fr] gap-x-3 gap-y-2 items-end">
                  <div className="space-y-2 min-w-0">
                    <Label htmlFor="create-exec-method" className="text-[13px]">
                      {t('Method')}
                    </Label>
                    <select
                      id="create-exec-method"
                      value={method}
                      disabled={fieldsDisabled}
                      onChange={(e) =>
                        setMethod(e.target.value as ExecutionMethod)
                      }
                      className={cn(selectFieldClassName, 'cursor-pointer')}
                    >
                      {HTTP_METHODS.map((m) => (
                        <option key={m.value} value={m.value}>
                          {m.label}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div className="space-y-2 min-w-0">
                    <Label htmlFor="create-exec-path" className="text-[13px]">
                      {t('Path')}
                    </Label>
                    <Input
                      id="create-exec-path"
                      value={path}
                      disabled={fieldsDisabled}
                      onChange={(e) => setPath(e.target.value)}
                      placeholder="/"
                      className="h-9 text-[13px] font-mono"
                    />
                  </div>
                </div>

                <div className="border-t border-border pt-4">
                  <div className="flex items-center gap-2 mb-2">
                    <span className="text-[14px] font-medium">
                      {t('Headers')}
                    </span>
                    <Badge
                      variant="secondary"
                      className="text-[11px] font-normal"
                    >
                      {t('Optional')}
                    </Badge>
                  </div>
                  <p className="text-[13px] text-muted-foreground mb-3">
                    {t(
                      'Provide essential metadata to define the content type, authentication details, and the expected response format.',
                    )}
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
                          disabled={fieldsDisabled}
                          onChange={(e) =>
                            updateHeader(row.id, { key: e.target.value })
                          }
                          list="header-keys-suggestions"
                          placeholder={t('Header name')}
                          className="h-9 text-[13px] w-[180px] shrink-0"
                        />
                        <Input
                          value={row.value}
                          disabled={fieldsDisabled}
                          onChange={(e) =>
                            updateHeader(row.id, { value: e.target.value })
                          }
                          placeholder={t('Enter value')}
                          className="h-9 text-[13px] flex-1 min-w-[120px]"
                        />
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          disabled={fieldsDisabled}
                          className="h-9 w-9 p-0 shrink-0 text-muted-foreground hover:text-foreground"
                          onClick={() => removeHeader(row.id)}
                          aria-label={t('Remove header')}
                        >
                          <X className="h-4 w-4" />
                        </Button>
                      </div>
                    ))}
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      disabled={fieldsDisabled}
                      className="h-8 text-[13px] text-primary hover:text-primary"
                      onClick={addHeader}
                    >
                      <Plus className="me-1.5 h-3.5 w-3.5" />
                      {t('Add header')}
                    </Button>
                  </div>
                </div>

                <div className="border-t border-border pt-4">
                  <div className="flex items-center gap-2 mb-2">
                    <span className="text-[14px] font-medium">{t('Body')}</span>
                    <Badge
                      variant="secondary"
                      className="text-[11px] font-normal"
                    >
                      {t('Optional')}
                    </Badge>
                  </div>
                  <p className="text-[13px] text-muted-foreground mb-3">
                    {t(
                      'Provide the request body to include the main data you want to send to the server.',
                    )}
                  </p>
                  <Textarea
                    value={body}
                    disabled={fieldsDisabled}
                    onChange={(e) => setBody(e.target.value)}
                    placeholder={t('Enter request body here...')}
                    className="min-h-[100px] text-[13px] font-mono resize-y"
                    rows={4}
                  />
                </div>

                <div className="border-t border-border pt-4">
                  <div className="flex items-center justify-between gap-4">
                    <div className="min-w-0">
                      <Label
                        htmlFor="create-exec-async"
                        className="text-[14px] font-medium"
                      >
                        {t('Async execution')}
                      </Label>
                      <p className="text-[13px] text-muted-foreground mt-1">
                        {t(
                          'Return immediately and run in the background. View the response on the executions tab when it completes.',
                        )}
                      </p>
                    </div>
                    <Switch
                      id="create-exec-async"
                      checked={isAsync}
                      disabled={fieldsDisabled}
                      onCheckedChange={(checked) => {
                        setIsAsync(checked)
                        if (checked) setResponse(null)
                      }}
                      className="shrink-0"
                    />
                  </div>
                </div>
              </div>
            </div>
          </div>

          {response && !isAsync && (
            <div className="flex-shrink-0 border-t border-border bg-muted/30 overflow-hidden">
              <div className="px-6 py-3 border-b border-border">
                <span className="text-[13px] font-medium">
                  {t('Response')}
                </span>
                <Badge
                  variant={response.ok ? 'default' : 'destructive'}
                  className="ms-2 text-[11px]"
                >
                  {response.status} {response.statusText}
                </Badge>
              </div>
              <div className="max-h-[200px] overflow-y-auto">
                <div className="px-6 py-3 border-b border-border">
                  <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground mb-2">
                    {t('Headers')}
                  </p>
                  <div className="space-y-1">
                    {response.headers.map((h) => (
                      <div
                        key={`${h.name}-${h.value}`}
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
                        {t('No headers')}
                      </p>
                    )}
                  </div>
                </div>
                <div className="px-6 py-3">
                  <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground mb-2">
                    {t('Body')}
                  </p>
                  <pre className="text-[12px] font-mono whitespace-pre-wrap break-words bg-background/50 rounded border border-border p-3 overflow-x-auto">
                    {response.body
                      ? formatResponseBody(response.body)
                      : t('(empty)')}
                  </pre>
                </div>
              </div>
            </div>
          )}
        </div>

        <div className="flex-shrink-0 flex items-center justify-start gap-2 border-t border-border bg-muted/30 px-6 py-4">
          <Button type="submit" disabled={!canExecute || isExecuting}>
            <Play className="me-1.5 h-4 w-4" />
            {t('Execute')}
          </Button>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
          >
            {t('Cancel')}
          </Button>
        </div>
      </form>
    </>
  )
}
