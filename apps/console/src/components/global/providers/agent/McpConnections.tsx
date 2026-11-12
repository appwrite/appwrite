import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useParams } from '@tanstack/react-router'
import { Loader2, RefreshCw, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { McpIcon } from '@/components/global/shared/McpIcon'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import {
  ASSISTANT_MCP_OAUTH_MESSAGE_TYPE,
  completeMcpOAuthConnect,
  connectMcpOAuthSilently,
  readPendingMcpOAuthSession,
  startMcpOAuthConnect,
  type McpOAuthCallbackMessage,
} from '@/lib/assistant/mcp-oauth'
import {
  APPWRITE_ASSISTANT_MCP_DESCRIPTION,
  APPWRITE_ASSISTANT_MCP_ID,
  APPWRITE_ASSISTANT_MCP_NAME,
  isAppwriteMcpConnectionCurrent,
  getAppwriteAssistantMcpConnectInput,
} from '@/lib/assistant/mcp-appwrite'
import { useDebugMcpEndpoint } from '@/hooks/use-debug-mcp-endpoint'
import {
  useAssistantMcpConnections,
  useDeleteAssistantMcpConnection,
  useUpdateAssistantMcpConnection,
  useUpsertAssistantMcpConnection,
  type AssistantMcpConnection,
} from '@/lib/react-query/hooks'

const TOP_LEVEL_CALLBACK_KEY = 'assistant.mcp.oauth.callback'

type McpListItem = {
  id: string
  name: string
  url: string
  description?: string
  connection?: AssistantMcpConnection
  isAppwrite: boolean
}

function hostFromUrl(url: string): string {
  try {
    return new URL(url).host
  } catch {
    return url
  }
}

function ConnectionStatusBadge({
  connected,
  needsReconnect,
}: {
  connected: boolean
  needsReconnect: boolean
}) {
  const t = useT()
  if (connected) {
    return (
      <Badge variant="success" className="text-[10px] shrink-0">
        {t('Connected')}
      </Badge>
    )
  }
  if (needsReconnect) {
    return (
      <Badge variant="warning" className="text-[10px] shrink-0">
        {t('Reconnect')}
      </Badge>
    )
  }
  return (
    <Badge variant="info" className="text-[10px] shrink-0">
      {t('Not connected')}
    </Badge>
  )
}

function useMcpConnectionsController(options?: {
  onConnected?: () => void
}) {
  const t = useT()
  const [connecting, setConnecting] = useState(false)
  const resumeAttemptedRef = useRef(false)
  const onConnectedRef = useRef(options?.onConnected)
  onConnectedRef.current = options?.onConnected
  const { effectiveUrl: appwriteMcpUrl } = useDebugMcpEndpoint()
  const { data: connections = [], isLoading } = useAssistantMcpConnections()
  const upsertMutation = useUpsertAssistantMcpConnection()
  const updateMutation = useUpdateAssistantMcpConnection()
  const deleteMutation = useDeleteAssistantMcpConnection()

  const appwriteConnection = useMemo(
    () =>
      connections.find((connection) => connection.$id === APPWRITE_ASSISTANT_MCP_ID),
    [connections],
  )
  const appwriteConnectionRef = useRef(appwriteConnection)
  appwriteConnectionRef.current = appwriteConnection

  const hasActiveMcp = useMemo(
    () =>
      connections.some((connection) => {
        if (!connection.enabled) return false
        if (connection.$id === APPWRITE_ASSISTANT_MCP_ID) {
          return isAppwriteMcpConnectionCurrent(connection, appwriteMcpUrl)
        }
        return connection.hasTokens
      }),
    [appwriteMcpUrl, connections],
  )

  const listItems = useMemo<McpListItem[]>(() => {
    const otherConnections = connections.filter(
      (connection) => connection.$id !== APPWRITE_ASSISTANT_MCP_ID,
    )
    return [
      {
        id: APPWRITE_ASSISTANT_MCP_ID,
        name: APPWRITE_ASSISTANT_MCP_NAME,
        url: appwriteMcpUrl,
        description: APPWRITE_ASSISTANT_MCP_DESCRIPTION,
        connection: appwriteConnection,
        isAppwrite: true,
      },
      ...otherConnections.map((connection) => ({
        id: connection.$id,
        name: connection.name || connection.$id,
        url: connection.url,
        description: connection.description || undefined,
        connection,
        isAppwrite: false,
      })),
    ]
  }, [appwriteConnection, appwriteMcpUrl, connections])

  const isBusy =
    connecting ||
    upsertMutation.isPending ||
    updateMutation.isPending ||
    deleteMutation.isPending

  const persistConnectedCredentials = async (code: string) => {
    const result = await completeMcpOAuthConnect({ code })
    await upsertMutation.mutateAsync({
      mcpId: result.mcpId,
      name: result.name,
      url: result.url,
      description: result.description,
      enabled: true,
      status: 'connected',
      tokens: JSON.stringify(result.tokens),
      clientInfo: JSON.stringify(result.clientInfo),
      exists: !!appwriteConnectionRef.current,
    })
    toast.success(t('Appwrite MCP connected'))
    onConnectedRef.current?.()
  }

  const persistSilentResult = async () => {
    const result = await connectMcpOAuthSilently(
      getAppwriteAssistantMcpConnectInput(),
    )
    await upsertMutation.mutateAsync({
      mcpId: result.mcpId,
      name: result.name,
      url: result.url,
      description: result.description,
      enabled: true,
      status: 'connected',
      tokens: JSON.stringify(result.tokens),
      clientInfo: JSON.stringify(result.clientInfo),
      exists: !!appwriteConnectionRef.current,
    })
    toast.success(t('Appwrite MCP connected'))
    onConnectedRef.current?.()
  }

  // Resume top-level redirect fallback (popup blocked → full-page OAuth).
  useEffect(() => {
    if (resumeAttemptedRef.current) return
    if (typeof window === 'undefined') return
    const raw = sessionStorage.getItem(TOP_LEVEL_CALLBACK_KEY)
    if (!raw) return
    resumeAttemptedRef.current = true
    sessionStorage.removeItem(TOP_LEVEL_CALLBACK_KEY)

    let payload: McpOAuthCallbackMessage
    try {
      payload = JSON.parse(raw) as McpOAuthCallbackMessage
    } catch {
      return
    }
    if (payload.type !== ASSISTANT_MCP_OAUTH_MESSAGE_TYPE) return

    if (payload.status === 'error') {
      toast.error(payload.errorDescription || payload.error)
      return
    }

    const pending = readPendingMcpOAuthSession()
    if (!pending || pending.state !== payload.state) {
      toast.error(t('Failed to connect Appwrite MCP'))
      return
    }

    setConnecting(true)
    void persistConnectedCredentials(payload.code)
      .catch((error) => {
        toast.error(getErrorMessage(error, t('Failed to connect Appwrite MCP')))
      })
      .finally(() => {
        setConnecting(false)
      })
    // eslint-disable-next-line react-hooks/exhaustive-deps -- one-shot resume on mount
  }, [])

  const handleConnectAppwrite = async () => {
    if (isBusy) return
    setConnecting(true)
    try {
      // Prefer silent first-party authorize + approve (no popup / consent UI).
      try {
        await persistSilentResult()
        return
      } catch {
        // Fall back to popup OAuth when silent connect is unavailable.
      }
      // Omit the seeded client id so popup DCR works on fresh instances
      // that do not have `appwrite-agent` registered yet.
      const { code } = await startMcpOAuthConnect({
        ...getAppwriteAssistantMcpConnectInput(),
        clientId: undefined,
      })
      await persistConnectedCredentials(code)
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to connect Appwrite MCP')))
    } finally {
      setConnecting(false)
    }
  }

  const handleToggleEnabled = async (
    connection: AssistantMcpConnection,
    enabled: boolean,
  ) => {
    try {
      await updateMutation.mutateAsync({
        mcpId: connection.$id,
        enabled,
      })
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to update MCP connection')))
    }
  }

  const handleDisconnect = async (mcpId: string) => {
    if (isBusy) return
    try {
      await deleteMutation.mutateAsync(mcpId)
      toast.success(
        mcpId === APPWRITE_ASSISTANT_MCP_ID
          ? t('Appwrite MCP disconnected')
          : t('MCP disconnected'),
      )
    } catch (error) {
      toast.error(
        getErrorMessage(
          error,
          mcpId === APPWRITE_ASSISTANT_MCP_ID
            ? t('Failed to disconnect Appwrite MCP')
            : t('Failed to disconnect MCP'),
        ),
      )
    }
  }

  return {
    t,
    connecting,
    isLoading,
    isBusy,
    hasActiveMcp,
    appwriteMcpUrl,
    listItems,
    upsertPending: upsertMutation.isPending,
    handleConnectAppwrite,
    handleToggleEnabled,
    handleDisconnect,
  }
}

type McpConnectionsListProps = {
  compact?: boolean
  controller: ReturnType<typeof useMcpConnectionsController>
}

function McpConnectionsList({
  compact = false,
  controller,
}: McpConnectionsListProps) {
  const {
    t,
    connecting,
    isLoading,
    isBusy,
    listItems,
    appwriteMcpUrl,
    upsertPending,
    handleConnectAppwrite,
    handleToggleEnabled,
    handleDisconnect,
  } = controller

  if (isLoading) {
    return (
      <div
        className={cn(
          'flex items-center justify-center gap-1.5 text-muted-foreground',
          compact ? 'px-4 py-6 text-[12px]' : 'px-6 py-8 text-[13px]',
        )}
      >
        <Loader2 className="h-3.5 w-3.5 animate-spin" />
        {t('Loading...')}
      </div>
    )
  }

  // No saved connections yet — keep Appwrite in the list as a connect target,
  // but show a proper empty state when the only row is the unconnected default.
  const onlyUnconnectedAppwrite =
    listItems.length === 1 &&
    listItems[0]?.isAppwrite &&
    !listItems[0]?.connection

  if (onlyUnconnectedAppwrite) {
    return (
      <div className={cn(compact ? 'px-4 py-6' : 'px-6 py-8')}>
        <EmptyState
          icon={McpIcon}
          iconSize="md"
          title="No MCP connections"
          description="Connect Appwrite MCP to give the agent tools for your projects."
          isEmpty
          action={
            <Button
              type="button"
              size="sm"
              className="h-9 gap-1.5 text-[13px]"
              disabled={isBusy}
              {...analyticsAttrs('agent-mcp-connect')}
              onClick={() => void handleConnectAppwrite()}
            >
              {connecting || upsertPending ? (
                <Loader2 className="h-3.5 w-3.5 animate-spin" />
              ) : null}
              {t('Connect')}
            </Button>
          }
        />
      </div>
    )
  }

  return (
    <ul className="divide-y divide-border">
      {listItems.map((item) => {
        const connected = item.isAppwrite
          ? isAppwriteMcpConnectionCurrent(item.connection, appwriteMcpUrl)
          : !!item.connection?.hasTokens
        const needsReconnect =
          item.isAppwrite
            ? !!item.connection &&
              (!item.connection.hasTokens ||
                !isAppwriteMcpConnectionCurrent(item.connection, appwriteMcpUrl))
            : !!item.connection && !item.connection.hasTokens
        const enabled = item.connection?.enabled ?? false

        return (
          <li key={item.id} className="overflow-hidden">
            <div
              className={cn(
                'flex items-start gap-3',
                compact ? 'px-4 py-3' : 'px-6 py-4',
              )}
            >
              <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-border bg-muted/40 text-muted-foreground">
                {item.isAppwrite ? (
                  <img
                    src="/icons/appwrite.svg"
                    alt=""
                    className="h-4 w-4"
                    aria-hidden
                  />
                ) : (
                  <McpIcon className="h-4 w-4" />
                )}
              </div>

              <div className="min-w-0 flex-1">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="truncate text-[13px] font-medium text-foreground">
                      {t(item.name)}
                    </p>
                    <p className="truncate text-[11px] text-muted-foreground">
                      {hostFromUrl(item.url)}
                    </p>
                  </div>
                  <ConnectionStatusBadge
                    connected={connected}
                    needsReconnect={needsReconnect}
                  />
                </div>

                {item.description ? (
                  <p className="mt-1.5 text-[11px] leading-relaxed text-muted-foreground">
                    {t(item.description)}
                  </p>
                ) : null}
              </div>
            </div>

            <div
              className={cn(
                'flex items-center justify-between gap-2 border-t border-border bg-muted/30',
                compact ? 'px-4 py-2.5' : 'px-6 py-3',
              )}
            >
              {item.connection ? (
                <label className="flex items-center gap-2 text-[11px] text-muted-foreground">
                  <Switch
                    checked={enabled}
                    onCheckedChange={(checked) => {
                      if (!item.connection) return
                      void handleToggleEnabled(item.connection, checked)
                    }}
                    disabled={isBusy || !connected}
                    aria-label={t('Enable MCP')}
                  />
                  <span>{enabled ? t('Enabled') : t('Disabled')}</span>
                </label>
              ) : (
                <span className="text-[11px] text-muted-foreground">
                  {t('OAuth required')}
                </span>
              )}

              <div className="flex items-center gap-1.5">
                {item.isAppwrite ? (
                  <Button
                    type="button"
                    size="sm"
                    variant={connected ? 'outline' : 'default'}
                    className="h-7 px-2.5 text-[11px]"
                    disabled={isBusy}
                    {...analyticsAttrs('agent-mcp-connect')}
                    onClick={() => void handleConnectAppwrite()}
                  >
                    {connecting || upsertPending ? (
                      <Loader2 className="me-1 h-3 w-3 animate-spin" />
                    ) : connected || needsReconnect ? (
                      <RefreshCw className="me-1 h-3 w-3" />
                    ) : null}
                    {connected || needsReconnect
                      ? t('Reconnect')
                      : t('Connect')}
                  </Button>
                ) : null}

                {item.connection ? (
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    className="h-7 px-2.5 text-[11px] text-muted-foreground hover:text-foreground"
                    disabled={isBusy}
                    {...analyticsAttrs('agent-mcp-disconnect')}
                    onClick={() => void handleDisconnect(item.id)}
                  >
                    <Trash2 className="me-1 h-3 w-3" />
                    {t('Disconnect')}
                  </Button>
                ) : null}
              </div>
            </div>
          </li>
        )
      })}
    </ul>
  )
}

/** Full settings-page list (no popover chrome). */
export function McpConnectionsPanel() {
  const controller = useMcpConnectionsController()
  return <McpConnectionsList controller={controller} />
}

/** Compact popover trigger used next to the model picker. */
export function McpConnections({
  onOpenSettings,
}: {
  /** Prefer in-surface settings (pane) over hard-navigating to the org agent page. */
  onOpenSettings?: () => void
} = {}) {
  const t = useT()
  const { orgId } = useParams({ strict: false })
  const [open, setOpen] = useState(false)
  const controller = useMcpConnectionsController({
    onConnected: () => setOpen(true),
  })

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="h-7 shrink-0 gap-1 px-2 text-[11px] font-medium text-muted-foreground hover:text-foreground"
          aria-label={
            controller.hasActiveMcp ? t('MCP ready') : t('MCP connections')
          }
        >
          {controller.connecting ? (
            <Loader2 className="h-3.5 w-3.5 animate-spin" />
          ) : (
            <span className="relative">
              <McpIcon className="h-3.5 w-3.5" />
              {controller.hasActiveMcp ? (
                <span className="absolute -end-0.5 -top-0.5 flex h-1.5 w-1.5">
                  <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-500/50" />
                  <span className="relative inline-flex h-1.5 w-1.5 rounded-full bg-emerald-500 ring-2 ring-background" />
                </span>
              ) : null}
            </span>
          )}
          <span>{t('MCP')}</span>
        </Button>
      </PopoverTrigger>
      <PopoverContent align="end" className="w-[360px] p-0">
        <div className="border-b border-border px-4 py-3">
          <div className="flex items-center gap-2">
            <div className="flex h-7 w-7 items-center justify-center rounded-md bg-muted text-muted-foreground">
              <McpIcon className="h-3.5 w-3.5" />
            </div>
            <div className="min-w-0">
              <p className="text-[13px] font-semibold text-foreground">
                {t('MCP connections')}
              </p>
              <p className="text-[11px] text-muted-foreground">
                {t('Servers available to the agent')}
              </p>
            </div>
          </div>
        </div>

        <div className="max-h-[360px] overflow-y-auto">
          <McpConnectionsList compact controller={controller} />
        </div>

        <div className="border-t border-border px-4 py-2.5">
          {onOpenSettings ? (
            <Button
              type="button"
              variant="ghost"
              className="h-8 w-full justify-start px-2 text-[12px] text-muted-foreground hover:text-foreground"
              onClick={() => {
                setOpen(false)
                onOpenSettings()
              }}
            >
              {t('Open MCP settings')}
            </Button>
          ) : orgId ? (
            <Button
              asChild
              type="button"
              variant="ghost"
              className="h-8 w-full justify-start px-2 text-[12px] text-muted-foreground hover:text-foreground"
            >
              <Link
                to="/organizations/$orgId/agent/settings/mcp"
                params={{ orgId }}
              >
                {t('Open MCP settings')}
              </Link>
            </Button>
          ) : (
            <Button
              asChild
              type="button"
              variant="ghost"
              className="h-8 w-full justify-start px-2 text-[12px] text-muted-foreground hover:text-foreground"
            >
              <Link to="/agent/settings/mcp">{t('Open MCP settings')}</Link>
            </Button>
          )}
        </div>
      </PopoverContent>
    </Popover>
  )
}
