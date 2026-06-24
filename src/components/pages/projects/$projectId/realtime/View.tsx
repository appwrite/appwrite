'use client'

import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type FormEvent,
  type KeyboardEvent,
  type ReactNode,
} from 'react'
import { useParams } from '@tanstack/react-router'
import {
  ArrowDownLeft,
  ArrowUpRight,
  Check,
  ChevronRight,
  Code2,
  Copy,
  Info,
  ListCollapse,
  ListTree,
  Loader2,
  MessagesSquare,
  Pause,
  Play,
  Plus,
  Radio,
  Route,
  Trash2,
  Unplug,
  X,
} from 'lucide-react'
import { format } from 'date-fns'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { EventEditorModal } from '@/components/global/shared/EventEditor'
import { SearchableSelect } from '@/components/global/shared/SearchableSelect'
import { ServiceHeader } from '../shared/ServiceHeader'
import { ConnectionCodeDialog } from './_components/ConnectionCodeDialog'
import { CollapsibleJsonView } from './_components/CollapsibleJsonView'
import { useProjectUsers } from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import {
  createRealtimeSession,
  createUserJwtForRealtime,
  getProjectRealtimeWebSocketUrl,
  type RealtimeMessageLog,
  type RealtimeSession,
  type RealtimeSessionError,
} from '@/lib/realtime/session-client'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'

const MAX_LOG_ENTRIES = 1000

const REALTIME_LAYOUT_GRID = 'lg:grid-cols-[minmax(0,320px)_minmax(0,1fr)]'

/** Sentinel value for guest mode in the act-as dropdown. */
const GUEST_ACTOR_ID = '__guest__'

const SUGGESTED_CHANNELS = [
  'account',
  'files',
  'teams',
  'databases.*.tables.*.rows.*',
] as const

type ConnectionStatus = 'disconnected' | 'connecting' | 'connected' | 'error'

type LogEntry = RealtimeMessageLog & { id: string }

type ActiveSubscription = {
  id: string
  channel: string
}

type ProjectUserOption = {
  $id: string
  name?: string
  email?: string
  phone?: string
}

function buildUserSelectItem(user: ProjectUserOption) {
  const name = user.name?.trim() || ''
  const email = user.email?.trim() || ''
  const phone = user.phone?.trim() || ''
  const id = user.$id

  const label = name || email || phone || id
  const descriptionParts = [
    name && name !== label ? name : '',
    email && email !== label ? email : '',
    phone && phone !== label ? phone : '',
    id !== label ? id : '',
  ].filter(Boolean)

  return {
    value: id,
    label,
    description: descriptionParts.join(' · ') || undefined,
    searchText: [name, email, phone, id].filter(Boolean).join(' '),
  }
}

function createLogId(): string {
  return `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`
}

function formatMessagePayload(message: RealtimeMessageLog['message']): string {
  try {
    return JSON.stringify(message, null, 2)
  } catch {
    return String(message)
  }
}

function formatLogTimestamp(iso: string): string {
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return iso
  return format(date, 'HH:mm:ss.SSS')
}

function formatPayloadSize(message: RealtimeMessageLog['message']): string {
  try {
    const bytes = new TextEncoder().encode(JSON.stringify(message)).length
    if (bytes < 1024) return `${bytes} B`
    return `${(bytes / 1024).toFixed(1)} KB`
  } catch {
    return '—'
  }
}

function MessagePayloadBlock({
  payload,
  message,
}: {
  payload: string
  message: RealtimeMessageLog['message']
}) {
  const size = formatPayloadSize(message)

  return (
    <CollapsibleJsonView
      payload={payload}
      footer={
        <div className="flex items-center justify-end border-t border-border bg-muted/20 px-3 py-1.5 font-mono text-[10px] tabular-nums text-muted-foreground">
          {size}
        </div>
      }
    />
  )
}

function RealtimePanelHeader({
  title,
  className,
  actions,
}: {
  title: string
  className?: string
  actions?: ReactNode
}) {
  return (
    <div
      className={cn(
        'flex h-12 min-h-12 max-h-12 shrink-0 items-center justify-between gap-3 overflow-hidden border-b border-border px-4',
        className,
      )}
    >
      <h3 className="shrink-0 text-[14px] font-semibold leading-none text-foreground">
        {title}
      </h3>
      {actions ? (
        <div className="flex shrink-0 items-center gap-2">{actions}</div>
      ) : null}
    </div>
  )
}

function connectionStatusLabel(
  status: ConnectionStatus,
  socketOpen: boolean,
): string {
  switch (status) {
    case 'connected':
      return socketOpen ? 'Connected' : 'Ready'
    case 'connecting':
      return 'Connecting'
    case 'error':
      return 'Connection failed'
    default:
      return 'Disconnected'
  }
}

function connectionStatusShortLabel(
  status: ConnectionStatus,
  socketOpen: boolean,
): string {
  switch (status) {
    case 'connected':
      return socketOpen ? 'Connected' : 'Ready'
    case 'connecting':
      return 'Connecting'
    case 'error':
      return 'Failed'
    default:
      return 'Offline'
  }
}

function connectionStatusSegmentClass(
  status: ConnectionStatus,
  socketOpen: boolean,
): string {
  switch (status) {
    case 'connected':
      return socketOpen
        ? 'bg-emerald-500/[0.06] text-emerald-700 dark:text-emerald-400'
        : 'bg-muted/50 text-muted-foreground'
    case 'connecting':
      return 'bg-amber-500/[0.06] text-amber-700 dark:text-amber-400'
    case 'error':
      return 'bg-destructive/[0.06] text-destructive'
    default:
      return 'bg-muted/50 text-muted-foreground'
  }
}

function RealtimeWebSocketUrlField({
  url,
  status,
  socketOpen,
}: {
  url: string
  status: ConnectionStatus
  socketOpen: boolean
}) {
  const [copied, setCopied] = useState(false)
  const statusLabel = connectionStatusShortLabel(status, socketOpen)
  const statusDescription = connectionStatusLabel(status, socketOpen)

  const handleCopy = useCallback(() => {
    void navigator.clipboard.writeText(url)
    setCopied(true)
    toast.success('WebSocket URL copied')
    window.setTimeout(() => setCopied(false), 2000)
  }, [url])

  return (
    <div
      className={cn(
        'flex w-full min-w-0 max-w-full items-stretch overflow-hidden rounded-md border border-border bg-background lg:inline-flex lg:w-max',
      )}
      role="status"
      aria-live="polite"
      aria-label={`Connection status: ${statusDescription}. ${url}`}
    >
      <Tooltip>
        <TooltipTrigger asChild>
          <div
            className={cn(
              'flex w-[7.25rem] shrink-0 items-center justify-center gap-1.5 border-r border-border px-2 sm:gap-2 sm:px-2.5',
              connectionStatusSegmentClass(status, socketOpen),
            )}
          >
            {status === 'connecting' ? (
              <Loader2
                className="h-3 w-3 shrink-0 animate-spin opacity-80"
                aria-hidden
              />
            ) : (
              <span
                className={cn(
                  'h-1.5 w-1.5 shrink-0 rounded-full',
                  status === 'connected' && socketOpen && 'bg-emerald-500',
                  status === 'connected' &&
                    !socketOpen &&
                    'bg-muted-foreground/60',
                  status === 'error' && 'bg-destructive',
                  status === 'disconnected' && 'bg-muted-foreground/40',
                )}
                aria-hidden
              />
            )}
            <span className="hidden truncate text-center text-[12px] sm:inline">
              {statusLabel}
            </span>
          </div>
        </TooltipTrigger>
        <TooltipContent side="bottom" className="text-[12px]">
          {statusDescription}
        </TooltipContent>
      </Tooltip>

      <div
        className="flex min-w-0 flex-1 items-center bg-muted/20 px-3"
        title={url}
      >
        <code className="block min-w-0 flex-1 truncate whitespace-nowrap font-mono text-[12px] text-foreground/90">
          {url}
        </code>
      </div>

      <button
        type="button"
        onClick={handleCopy}
        className="flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center border-l border-border text-muted-foreground transition-colors hover:bg-muted/40 hover:text-foreground"
        aria-label="Copy WebSocket URL"
      >
        {copied ? (
          <Check className="h-3.5 w-3.5 text-emerald-500" />
        ) : (
          <Copy className="h-3.5 w-3.5" />
        )}
      </button>
    </div>
  )
}

function messageTypeVariant(
  type: string,
  direction: RealtimeMessageLog['direction'],
): 'success' | 'warning' | 'error' | 'info' {
  if (type === 'error') return 'error'
  if (type === 'info' || type === 'disconnect') return 'info'
  if (type === 'pong' || type === 'ping') return 'warning'
  if (type === 'connected' || type === 'open') return 'success'
  if (type === 'close') return 'warning'
  if (direction === 'out') return 'info'
  return 'info'
}

function MessagesEmptyState({
  isConnected,
  hasSubscriptions,
}: {
  isConnected: boolean
  hasSubscriptions: boolean
}) {
  if (!isConnected) {
    return (
      <EmptyState
        variant="centered"
        icon={MessagesSquare}
        iconSize="md"
        title="No messages yet"
        description="Connect as guest or a project user, then subscribe to channels to inspect WebSocket traffic."
        isEmpty
        className="w-full"
      />
    )
  }

  if (!hasSubscriptions) {
    return (
      <EmptyState
        variant="centered"
        icon={MessagesSquare}
        iconSize="md"
        title="Waiting for subscriptions"
        description="Add a channel subscription to start receiving and logging Realtime frames."
        isEmpty
        className="w-full"
      />
    )
  }

  return (
    <EmptyState
      variant="centered"
      icon={Radio}
      iconSize="md"
      title="Listening for traffic"
      description="Incoming and outgoing WebSocket frames will appear here as they arrive."
      isEmpty
      className="w-full"
    />
  )
}

export function View() {
  const { projectId } = useParams({ strict: false })

  const websocketUrl = useMemo(
    () => (projectId ? getProjectRealtimeWebSocketUrl(projectId) : ''),
    [projectId],
  )

  const [actAsValue, setActAsValue] = useState(GUEST_ACTOR_ID)
  const [userSearch, setUserSearch] = useState('')
  const [connectionStatus, setConnectionStatus] =
    useState<ConnectionStatus>('disconnected')
  const [socketOpen, setSocketOpen] = useState(false)
  const [isConnecting, setIsConnecting] = useState(false)
  const [channelInput, setChannelInput] = useState('')
  const [channelBuilderOpen, setChannelBuilderOpen] = useState(false)
  const [logs, setLogs] = useState<LogEntry[]>([])
  const [expandedMessageIds, setExpandedMessageIds] = useState<Set<string>>(
    () => new Set(),
  )
  const [isPaused, setIsPaused] = useState(false)
  const [connectionCodeOpen, setConnectionCodeOpen] = useState(false)
  const [activeSubscriptions, setActiveSubscriptions] = useState<
    ActiveSubscription[]
  >([])

  const sessionRef = useRef<RealtimeSession | null>(null)
  const subscriptionsRef = useRef<Map<string, ActiveSubscription>>(new Map())
  const isPausedRef = useRef(isPaused)

  const { users, isLoading: usersLoading } = useProjectUsers(
    projectId ?? null,
    0,
    100,
    userSearch,
  )

  const userItems = useMemo(
    () => users.map((user) => buildUserSelectItem(user)),
    [users],
  )

  const actAsItems = useMemo(
    () => [
      {
        value: GUEST_ACTOR_ID,
        label: 'Guest',
        description: 'No session or JWT',
        searchText: 'guest unauthenticated public',
      },
      ...userItems,
    ],
    [userItems],
  )

  const isGuestActAs = actAsValue === GUEST_ACTOR_ID

  const subscribedChannels = useMemo(
    () => new Set(activeSubscriptions.map((entry) => entry.channel)),
    [activeSubscriptions],
  )

  const snippetChannels = useMemo(
    () => activeSubscriptions.map((entry) => entry.channel),
    [activeSubscriptions],
  )

  const isConnected = connectionStatus === 'connected'
  const canConnect = !!actAsValue && !isConnecting && !isConnected
  const canDisconnectAll =
    isConnected || isConnecting || activeSubscriptions.length > 0
  const canSubscribe = isConnected && !!channelInput.trim()
  const authControlsDisabled = isConnected || isConnecting

  useEffect(() => {
    isPausedRef.current = isPaused
  }, [isPaused])

  useEffect(() => {
    if (projectId) sdk.forProject(projectId)
  }, [projectId])

  const appendLog = useCallback((entry: RealtimeMessageLog) => {
    if (isPausedRef.current) return

    setLogs((current) => {
      const next = [{ ...entry, id: createLogId() }, ...current]
      return next.slice(0, MAX_LOG_ENTRIES)
    })
  }, [])

  const handleSessionError = useCallback((_error: RealtimeSessionError) => {
    setConnectionStatus('error')
    setSocketOpen(false)
  }, [])

  const handleSessionMessage = useCallback(
    (entry: RealtimeMessageLog) => {
      appendLog(entry)

      if (entry.message.type === 'error') {
        setConnectionStatus('error')
        setSocketOpen(false)
      }
    },
    [appendLog],
  )

  const teardownSession = useCallback(
    async (options?: { disconnectAll?: boolean }) => {
      subscriptionsRef.current.clear()
      setActiveSubscriptions([])

      const session = sessionRef.current
      sessionRef.current = null
      if (session) {
        try {
          if (options?.disconnectAll) {
            await session.disconnectAll()
          } else {
            await session.disconnect()
          }
        } catch {
          /* ignore */
        }
      }
    },
    [],
  )

  const handleDisconnect = useCallback(async () => {
    setIsConnecting(false)
    setSocketOpen(false)
    await teardownSession()
    setConnectionStatus('disconnected')
    appendLog({
      direction: 'out',
      timestamp: new Date().toISOString(),
      message: { type: 'disconnect', data: { reason: 'Client disconnected' } },
    })
  }, [appendLog, teardownSession])

  const handleDisconnectAll = useCallback(async () => {
    setIsConnecting(false)
    setSocketOpen(false)
    await teardownSession({ disconnectAll: true })
    setConnectionStatus('disconnected')
    appendLog({
      direction: 'out',
      timestamp: new Date().toISOString(),
      message: {
        type: 'disconnect',
        data: { reason: 'All connections disconnected' },
      },
    })
  }, [appendLog, teardownSession])

  useEffect(() => {
    return () => {
      void teardownSession()
    }
  }, [teardownSession])

  const handleConnect = useCallback(async () => {
    if (!projectId) return

    if (!actAsValue) {
      toast.error('Select guest or a project user to connect as.')
      return
    }

    setIsConnecting(true)
    setConnectionStatus('connecting')

    try {
      await teardownSession()

      const sessionAuth = isGuestActAs
        ? { mode: 'guest' as const }
        : {
            mode: 'user' as const,
            jwt: await createUserJwtForRealtime(projectId, actAsValue),
          }

      const session = createRealtimeSession(projectId, sessionAuth, {
        onMessage: handleSessionMessage,
        onOpen: () => {
          setSocketOpen(true)
          setConnectionStatus('connected')
        },
        onClose: () => {
          setSocketOpen(false)
        },
        onError: handleSessionError,
      })

      sessionRef.current = session
      await session.connect()

      appendLog({
        direction: 'out',
        timestamp: new Date().toISOString(),
        message: {
          type: 'info',
          data: {
            message: isGuestActAs
              ? 'Realtime session created as guest'
              : 'Realtime session created for project user',
            mode: isGuestActAs ? 'guest' : 'user',
            projectId,
            ...(isGuestActAs ? {} : { userId: actAsValue }),
            endpoint: websocketUrl,
          },
        },
      })
    } catch (error) {
      setConnectionStatus('error')
      const message = getErrorMessage(error)
      appendLog({
        direction: 'in',
        timestamp: new Date().toISOString(),
        message: { type: 'error', data: { message } },
      })
      await teardownSession()
    } finally {
      setIsConnecting(false)
    }
  }, [
    actAsValue,
    appendLog,
    handleSessionError,
    handleSessionMessage,
    isGuestActAs,
    projectId,
    teardownSession,
    websocketUrl,
  ])

  const subscribeToChannel = useCallback(
    async (rawChannel: string) => {
      const channel = rawChannel.trim()
      if (!channel) return

      const session = sessionRef.current
      if (!session || connectionStatus !== 'connected') {
        toast.error('Connect before subscribing to channels.')
        return
      }

      const existing = Array.from(subscriptionsRef.current.values()).some(
        (entry) => entry.channel === channel,
      )
      if (existing) {
        toast.error('Already subscribed to this channel.')
        return
      }

      try {
        const subscriptionId = await session.subscribe(channel)
        const entry: ActiveSubscription = { id: subscriptionId, channel }
        subscriptionsRef.current.set(subscriptionId, entry)
        setActiveSubscriptions(Array.from(subscriptionsRef.current.values()))
      } catch (error) {
        const message = getErrorMessage(error)
        appendLog({
          direction: 'in',
          timestamp: new Date().toISOString(),
          message: {
            type: 'error',
            data: { message: `Subscribe failed (${channel}): ${message}` },
          },
        })
      }
    },
    [appendLog, connectionStatus],
  )

  const handleSubscribeSubmit = useCallback(
    async (event: FormEvent) => {
      event.preventDefault()
      const channel = channelInput.trim()
      if (!channel) return
      await subscribeToChannel(channel)
      setChannelInput('')
    },
    [channelInput, subscribeToChannel],
  )

  const handleChannelBuilt = useCallback(
    async (channel: string) => {
      const trimmed = channel.trim()
      if (!trimmed) return
      await subscribeToChannel(trimmed)
      setChannelInput('')
    },
    [subscribeToChannel],
  )

  const handleOpenChannelBuilder = useCallback(() => {
    if (document.activeElement instanceof HTMLElement) {
      document.activeElement.blur()
    }
    window.setTimeout(() => setChannelBuilderOpen(true), 0)
  }, [])

  const handleUnsubscribe = useCallback(
    async (subscriptionId: string) => {
      const entry = subscriptionsRef.current.get(subscriptionId)
      if (!entry) return

      try {
        await sessionRef.current?.unsubscribe(subscriptionId)
      } catch {
        /* ignore */
      }

      subscriptionsRef.current.delete(subscriptionId)
      setActiveSubscriptions(Array.from(subscriptionsRef.current.values()))
    },
    [],
  )

  const handleClearLogs = useCallback(() => {
    setLogs([])
    setExpandedMessageIds(new Set())
  }, [])

  const allMessagesExpanded = useMemo(
    () =>
      logs.length > 0 &&
      logs.every((entry) => expandedMessageIds.has(entry.id)),
    [logs, expandedMessageIds],
  )

  const handleToggleAllMessages = useCallback(() => {
    if (allMessagesExpanded) {
      setExpandedMessageIds(new Set())
      return
    }

    setExpandedMessageIds(new Set(logs.map((entry) => entry.id)))
  }, [allMessagesExpanded, logs])

  const handleToggleMessageExpanded = useCallback((messageId: string) => {
    setExpandedMessageIds((current) => {
      const next = new Set(current)
      if (next.has(messageId)) {
        next.delete(messageId)
      } else {
        next.add(messageId)
      }
      return next
    })
  }, [])

  if (!projectId) return null

  return (
    <div className="flex h-full min-h-0 flex-col">
      <ServiceHeader title="Realtime" fullWidthBorder fullWidth />

      <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
        <TooltipProvider delayDuration={300}>
          <div
            className={cn(
              'border-b border-border bg-muted/20 lg:grid',
              REALTIME_LAYOUT_GRID,
            )}
          >
            <div className="flex min-w-0 flex-col gap-2 border-b border-border px-4 py-3 sm:flex-row sm:items-center sm:gap-3 lg:min-h-14 lg:border-b-0 lg:border-r">
              <Tooltip>
                <TooltipTrigger asChild>
                  <span className="cursor-default shrink-0 text-[12px] font-medium text-muted-foreground sm:w-auto">
                    Act as
                  </span>
                </TooltipTrigger>
                <TooltipContent side="bottom" className="max-w-xs text-[12px]">
                  {isGuestActAs
                    ? 'Guest connections do not send a session or JWT. Subscribe only to channels with public read permissions.'
                    : 'User connections create a JWT for the selected project user when you connect.'}
                </TooltipContent>
              </Tooltip>

              <div className="min-w-0 w-full flex-1">
                <SearchableSelect
                  value={actAsValue}
                  onValueChange={setActAsValue}
                  items={actAsItems}
                  placeholder={
                    usersLoading ? 'Loading users…' : 'Select guest or user'
                  }
                  searchPlaceholder="Search users or select guest…"
                  emptyMessage="No users found"
                  disabled={authControlsDisabled}
                  isFetching={usersLoading}
                  onSearchChange={setUserSearch}
                />
              </div>
            </div>

            <div className="flex min-w-0 flex-col gap-3 px-4 py-3 lg:min-h-14 lg:flex-row lg:items-center lg:gap-3">
              <div className="min-w-0 w-full lg:w-auto lg:flex-1 lg:overflow-hidden">
                <RealtimeWebSocketUrlField
                  url={websocketUrl}
                  status={connectionStatus}
                  socketOpen={socketOpen}
                />
              </div>
              <div className="flex w-full shrink-0 flex-wrap items-center gap-2 sm:w-auto lg:ml-auto">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 flex-1 text-[13px] sm:flex-none"
                  onClick={() => setConnectionCodeOpen(true)}
                >
                  <Code2 className="mr-1.5 h-4 w-4" />
                  SDK code
                </Button>
                {isConnected ? (
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-9 flex-1 text-[13px] sm:flex-none"
                    onClick={() => void handleDisconnect()}
                    disabled={isConnecting}
                  >
                    Disconnect
                  </Button>
                ) : (
                  <Button
                    size="sm"
                    className="h-9 flex-1 text-[13px] sm:flex-none"
                    onClick={() => void handleConnect()}
                    disabled={!canConnect}
                  >
                    {isConnecting ? (
                      <>
                        <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                        Connecting
                      </>
                    ) : (
                      'Connect'
                    )}
                  </Button>
                )}
              </div>
            </div>
          </div>
        </TooltipProvider>

        <div
          className={cn(
            'flex min-h-0 flex-1 flex-col overflow-hidden lg:grid',
            REALTIME_LAYOUT_GRID,
            'lg:grid-rows-[3rem_minmax(0,1fr)]',
          )}
        >
          <RealtimePanelHeader
            title="Subscriptions"
            className="order-1 lg:col-start-1 lg:row-start-1 lg:border-r lg:border-border"
            actions={
              <span className="rounded-md border border-border bg-muted/30 px-2 py-0.5 font-mono text-[11px] tabular-nums text-muted-foreground">
                {activeSubscriptions.length}
              </span>
            }
          />

          <div className="order-2 flex min-h-0 flex-col overflow-hidden border-b border-border lg:col-start-1 lg:row-start-2 lg:min-h-0 lg:border-b-0 lg:border-r">
            <div className="space-y-4 p-4">
              <form onSubmit={(event) => void handleSubscribeSubmit(event)}>
                <div className="flex gap-2">
                  <Input
                    value={channelInput}
                    onChange={(event) => setChannelInput(event.target.value)}
                    placeholder="e.g. account"
                    className="h-9 min-w-0 flex-1 font-mono text-[13px]"
                    disabled={!isConnected}
                    spellCheck={false}
                  />
                  <TooltipProvider delayDuration={0}>
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          className="h-9 shrink-0 px-3"
                          disabled={!isConnected}
                          onClick={handleOpenChannelBuilder}
                        >
                          <Route className="h-4 w-4" />
                        </Button>
                      </TooltipTrigger>
                      <TooltipContent side="bottom">
                        <p>Build channel</p>
                      </TooltipContent>
                    </Tooltip>
                  </TooltipProvider>
                  <Button
                    type="submit"
                    size="sm"
                    className="h-9 shrink-0 px-3"
                    disabled={!canSubscribe}
                  >
                    <Plus className="h-4 w-4" />
                  </Button>
                </div>
              </form>

              <div className="space-y-2">
                <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Suggested
                </p>
                <div className="flex flex-wrap gap-1.5">
                  {SUGGESTED_CHANNELS.map((channel) => {
                    const isAlreadySubscribed = subscribedChannels.has(channel)

                    return (
                      <Button
                        key={channel}
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-7 font-mono text-[11px]"
                        disabled={!isConnected || isAlreadySubscribed}
                        onClick={() => void subscribeToChannel(channel)}
                      >
                        {channel}
                      </Button>
                    )
                  })}
                </div>
              </div>
            </div>

            <div className="min-h-0 flex-1 overflow-hidden border-t border-border">
              {activeSubscriptions.length === 0 ? (
                <div className="flex h-full min-h-[160px] items-center justify-center px-4 text-center text-[13px] text-muted-foreground">
                  {isConnected
                    ? 'No active subscriptions.'
                    : 'Connect to start subscribing.'}
                </div>
              ) : (
                <div className="h-full overflow-y-auto overscroll-contain">
                  <ul className="divide-y divide-border">
                    {activeSubscriptions.map((entry) => (
                      <li
                        key={entry.id}
                        className="flex items-center gap-2 px-4 py-3"
                      >
                        <Radio className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                        <code className="min-w-0 flex-1 truncate font-mono text-[12px] text-foreground">
                          {entry.channel}
                        </code>
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="h-7 w-7 shrink-0 p-0 text-muted-foreground hover:text-foreground"
                          aria-label={`Unsubscribe from ${entry.channel}`}
                          onClick={() => void handleUnsubscribe(entry.id)}
                        >
                          <X className="h-4 w-4" />
                        </Button>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </div>

            <div className="shrink-0 border-t border-border bg-muted/30 px-4 py-3">
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-9 w-full text-[13px]"
                disabled={!canDisconnectAll}
                onClick={() => void handleDisconnectAll()}
              >
                <Unplug className="mr-1.5 h-4 w-4" />
                Disconnect all
              </Button>
            </div>
          </div>

          <RealtimePanelHeader
            title="Messages"
            className="order-3 lg:col-start-2 lg:row-start-1"
            actions={
              <>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-7 text-[12px]"
                  onClick={() => setIsPaused((current) => !current)}
                >
                  {isPaused ? (
                    <>
                      <Play className="mr-1.5 h-3.5 w-3.5" />
                      Resume
                    </>
                  ) : (
                    <>
                      <Pause className="mr-1.5 h-3.5 w-3.5" />
                      Pause
                    </>
                  )}
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-7 text-[12px]"
                  onClick={handleClearLogs}
                  disabled={logs.length === 0}
                >
                  <Trash2 className="mr-1.5 h-3.5 w-3.5" />
                  Clear
                </Button>
                <TooltipProvider delayDuration={0}>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <button
                        type="button"
                        className="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground focus:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-40"
                        onClick={handleToggleAllMessages}
                        disabled={logs.length === 0}
                        aria-label={
                          allMessagesExpanded ? 'Collapse all' : 'Expand all'
                        }
                      >
                        {allMessagesExpanded ? (
                          <ListCollapse className="h-3.5 w-3.5" />
                        ) : (
                          <ListTree className="h-3.5 w-3.5" />
                        )}
                      </button>
                    </TooltipTrigger>
                    <TooltipContent side="bottom">
                      <p>{allMessagesExpanded ? 'Collapse all' : 'Expand all'}</p>
                    </TooltipContent>
                  </Tooltip>
                </TooltipProvider>
              </>
            }
          />

          <div className="order-4 flex min-h-[280px] flex-col overflow-hidden lg:col-start-2 lg:row-start-2 lg:min-h-0">
            <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
              {logs.length === 0 ? (
                <div className="flex min-h-[200px] flex-1 items-center justify-center px-4 py-12">
                  <div className="w-full max-w-sm">
                    <MessagesEmptyState
                      isConnected={isConnected}
                      hasSubscriptions={activeSubscriptions.length > 0}
                    />
                  </div>
                </div>
              ) : (
                <div>
                  {logs.map((entry, index) => (
                    <MessageRow
                      key={entry.id}
                      entry={entry}
                      sequence={logs.length - index}
                      expanded={expandedMessageIds.has(entry.id)}
                      onToggle={() => handleToggleMessageExpanded(entry.id)}
                    />
                  ))}
                </div>
              )}
            </div>
          </div>
        </div>
      </div>

      <ConnectionCodeDialog
        open={connectionCodeOpen}
        onOpenChange={setConnectionCodeOpen}
        projectId={projectId}
        channels={snippetChannels}
      />

      <EventEditorModal
        open={channelBuilderOpen}
        onOpenChange={setChannelBuilderOpen}
        onCreated={handleChannelBuilt}
        projectId={projectId}
        channelMode
        initialValue={channelInput.trim() || undefined}
      />
    </div>
  )
}

function MessageDirectionIcon({ entry }: { entry: LogEntry }) {
  const type = entry.message.type || 'unknown'

  if (type === 'info') {
    return (
      <Info
        className="h-3.5 w-3.5 shrink-0 text-muted-foreground"
        aria-label="Info message"
      />
    )
  }

  if (entry.direction === 'in') {
    return (
      <ArrowDownLeft
        className="h-3.5 w-3.5 shrink-0 text-sky-600 dark:text-sky-400"
        aria-label="Incoming message"
      />
    )
  }

  return (
    <ArrowUpRight
      className="h-3.5 w-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"
      aria-label="Outgoing message"
    />
  )
}

function MessageRow({
  entry,
  sequence,
  expanded,
  onToggle,
}: {
  entry: LogEntry
  sequence: number
  expanded: boolean
  onToggle: () => void
}) {
  const type = entry.message.type || 'unknown'
  const payload = useMemo(
    () => formatMessagePayload(entry.message),
    [entry.message],
  )

  const handleToggle = useCallback(() => {
    onToggle()
  }, [onToggle])

  const handleRowKeyDown = useCallback(
    (event: KeyboardEvent) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault()
        handleToggle()
      }
    },
    [handleToggle],
  )

  return (
    <div className="border-b border-border last:border-b-0">
      <div
        role="button"
        tabIndex={0}
        aria-expanded={expanded}
        onClick={handleToggle}
        onKeyDown={handleRowKeyDown}
        className="grid cursor-pointer grid-cols-[auto_1.75rem_0.875rem_minmax(0,1fr)] items-center gap-x-1.5 px-4 py-2.5 transition-colors hover:bg-muted/30"
      >
        <MessageDirectionIcon entry={entry} />

        <span className="text-right font-mono text-[11px] tabular-nums leading-none text-muted-foreground">
          {sequence}
        </span>

        <ChevronRight
          className={cn(
            'h-3.5 w-3.5 shrink-0 text-muted-foreground transition-transform duration-200',
            expanded && 'rotate-90',
          )}
          aria-hidden
        />

        <div className="flex min-w-0 items-center justify-between gap-3">
          <Badge
            variant={messageTypeVariant(type, entry.direction)}
            className="h-5 shrink-0 font-mono text-[10px] uppercase"
          >
            {type}
          </Badge>
          <span
            className="shrink-0 font-mono text-[11px] tabular-nums text-muted-foreground"
            title={entry.timestamp}
          >
            {formatLogTimestamp(entry.timestamp)}
          </span>
        </div>
      </div>

      {expanded ? (
        <div className="grid grid-cols-[auto_1.75rem_0.875rem_minmax(0,1fr)] gap-x-1.5 px-4 pb-3">
          <div aria-hidden />
          <div aria-hidden />
          <div aria-hidden />
          <div className="min-w-0 pt-2">
            <MessagePayloadBlock payload={payload} message={entry.message} />
          </div>
        </div>
      ) : null}
    </div>
  )
}
