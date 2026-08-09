'use client'

import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type KeyboardEvent,
  type ReactNode,
} from 'react'
import { useParams } from '@tanstack/react-router'
import {
  Check,
  ChevronRight,
  Code2,
  Copy,
  ListCollapse,
  ListTree,
  Loader2,
  MessagesSquare,
  Pause,
  Play,
  Radio,
  Trash2,
  Unplug,
  X,
} from 'lucide-react'
import { format } from 'date-fns'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
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
import { MessagesFilterBar } from './_components/MessagesFilterBar'
import { MessageDirectionIcon } from './_components/MessageDirectionIcon'
import { ReconnectBanner } from './_components/ReconnectBanner'
import { ConfigurationPanel } from './_components/ConfigurationPanel'
import { InsertSampleMessageMenu } from './_components/InsertSampleMessageMenu'
import { useRealtimeDebuggerConfig } from '@/hooks/use-realtime-debugger-config'
import { useProjectUsers } from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import {
  createRealtimeSession,
  createUserJwtForRealtime,
  getProjectRealtimeWebSocketUrl,
  type RealtimeMessageLog,
  type RealtimeReconnectState,
  type RealtimeSession,
  type RealtimeSessionError,
} from '@/lib/realtime/session-client'
import {
  formatSummaryList,
  getEventFrameSummary,
} from '@/lib/realtime/event-frame-summary'
import {
  createDefaultMessageLogFilters,
  matchesMessageLogFilters,
  type MessageLogFilters,
} from '@/lib/realtime/message-filters'
import {
  createMockMessageLog,
  type MockMessageSampleId,
} from '@/lib/realtime/mock-message-samples'
import {
  entriesToQueryStrings,
  normalizeSubscriptionQueries,
  subscriptionsMatch,
  type SubscriptionQueryEntry,
} from '@/lib/realtime/subscription-queries'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'

const MAX_LOG_ENTRIES = 1000

const REALTIME_LAYOUT_GRID = 'lg:grid-cols-[minmax(0,320px)_minmax(0,1fr)]'

/** Sentinel value for guest mode in the act-as dropdown. */
const GUEST_ACTOR_ID = '__guest__'

type ConnectionStatus = 'disconnected' | 'connecting' | 'connected' | 'error'

type LogEntry = RealtimeMessageLog & { id: string; isSample?: boolean }

type ActiveSubscription = {
  id: string
  channel: string
  queries: string[]
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
    return '-'
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
  const t = useT()
  const [copied, setCopied] = useState(false)
  const statusLabel = t(connectionStatusShortLabel(status, socketOpen))
  const statusDescription = t(connectionStatusLabel(status, socketOpen))

  const handleCopy = useCallback(() => {
    void navigator.clipboard.writeText(url)
    setCopied(true)
    toast.success(t('WebSocket URL copied'))
    window.setTimeout(() => setCopied(false), 2000)
  }, [url, t])

  return (
    <div
      className={cn(
        'flex w-full min-w-0 max-w-full items-stretch overflow-hidden rounded-md border border-border bg-background lg:inline-flex lg:w-max',
      )}
      role="status"
      aria-live="polite"
      aria-label={`${t('Connection status')}: ${statusDescription}. ${url}`}
    >
      <Tooltip>
        <TooltipTrigger asChild>
          <div
            className={cn(
              'flex w-[7.25rem] shrink-0 items-center justify-center gap-1.5 border-e border-border px-2 sm:gap-2 sm:px-2.5',
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
        className="flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center border-s border-border text-muted-foreground transition-colors hover:bg-muted/40 hover:text-foreground"
        aria-label={t('Copy WebSocket URL')}
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
  const t = useT()
  if (!isConnected) {
    return (
      <EmptyState
        variant="centered"
        icon={MessagesSquare}
        iconSize="md"
        title={t('No messages yet') /* pragma: allowlist secret */}
        description={t('Connect as guest or a project user, then subscribe to channels to inspect WebSocket traffic. You can also insert sample frames to preview payload structure.')}
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
        title={t('Waiting for subscriptions')}
        description={t('Add a channel subscription to start receiving and logging Realtime frames.')}
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
      title={t('Listening for traffic')}
      description={t('Incoming and outgoing WebSocket frames will appear here as they arrive.')}
      isEmpty
      className="w-full"
    />
  )
}

export function View() {
  const t = useT()
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
  const [channelBuilderOpen, setChannelBuilderOpen] = useState(false)
  const [messageFilters, setMessageFilters] = useState<MessageLogFilters>(
    createDefaultMessageLogFilters,
  )
  const [reconnectState, setReconnectState] = useState<RealtimeReconnectState>({
    status: 'idle',
    attempt: 0,
    maxAttempts: 8,
  })
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

  const {
    config: debuggerConfig,
    addSubscription,
    removeSubscription,
    addSubscriptionQuery,
    removeSubscriptionQuery,
  } = useRealtimeDebuggerConfig(projectId)

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
        label: t('Guest'),
        description: t('No session or JWT'),
        searchText: 'guest unauthenticated public',
      },
      ...userItems,
    ],
    [userItems, t],
  )

  const isGuestActAs = actAsValue === GUEST_ACTOR_ID

  const configuredSubscriptions = debuggerConfig.subscriptions

  const filteredLogs = useMemo(
    () => logs.filter((entry) => matchesMessageLogFilters(entry, messageFilters)),
    [logs, messageFilters],
  )

  const isConnected = connectionStatus === 'connected'
  const canConnect = !!actAsValue && !isConnecting && !isConnected
  const canDisconnectAll =
    isConnected || isConnecting || activeSubscriptions.length > 0
  const subscriptionCount = configuredSubscriptions.length
  const authControlsDisabled = isConnected || isConnecting

  const isSubscriptionLive = useCallback(
    (subscriptionId: string) => {
      const configured = configuredSubscriptions.find(
        (entry) => entry.id === subscriptionId,
      )
      if (!configured) return false

      const queryStrings = entriesToQueryStrings(configured.queries)
      return activeSubscriptions.some((entry) =>
        subscriptionsMatch(
          entry.channel,
          entry.queries,
          configured.channel,
          queryStrings,
        ),
      )
    },
    [activeSubscriptions, configuredSubscriptions],
  )

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

  const appendSampleLog = useCallback((sampleId: MockMessageSampleId) => {
    const entry = createMockMessageLog(sampleId)
    setLogs((current) => {
      const next = [{ ...entry, id: createLogId(), isSample: true }, ...current]
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
    setReconnectState({ status: 'idle', attempt: 0, maxAttempts: 8 })
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
    setReconnectState({ status: 'idle', attempt: 0, maxAttempts: 8 })
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
      toast.error(t('Select guest or a project user to connect as.'))
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
        onReconnectStateChange: setReconnectState,
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

      for (const subscription of debuggerConfig.subscriptions) {
        const queryStrings = entriesToQueryStrings(subscription.queries)
        const alreadyActive = Array.from(subscriptionsRef.current.values()).some(
          (entry) =>
            subscriptionsMatch(
              entry.channel,
              entry.queries,
              subscription.channel,
              queryStrings,
            ),
        )
        if (alreadyActive) continue

        try {
          const subscriptionId = await session.subscribe(
            subscription.channel,
            queryStrings,
          )
          const entry: ActiveSubscription = {
            id: subscriptionId,
            channel: subscription.channel,
            queries: queryStrings,
          }
          subscriptionsRef.current.set(subscriptionId, entry)
        } catch (error) {
          const message = getErrorMessage(error)
          appendLog({
            direction: 'in',
            timestamp: new Date().toISOString(),
            message: {
              type: 'error',
              data: {
                message: `Subscribe failed (${subscription.channel}): ${message}`,
              },
            },
          })
        }
      }
      setActiveSubscriptions(Array.from(subscriptionsRef.current.values()))
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
    debuggerConfig.subscriptions,
    handleSessionError,
    handleSessionMessage,
    isGuestActAs,
    projectId,
    teardownSession,
    websocketUrl,
    t,
  ])

  const subscribeWebSocket = useCallback(
    async (rawChannel: string, rawQueries: string[] = []) => {
      const channel = rawChannel.trim()
      if (!channel) return

      const queries = normalizeSubscriptionQueries(rawQueries)

      const session = sessionRef.current
      if (!session || connectionStatus !== 'connected') {
        return
      }

      const existing = Array.from(subscriptionsRef.current.values()).some(
        (entry) => subscriptionsMatch(entry.channel, entry.queries, channel, queries),
      )
      if (existing) {
        return
      }

      try {
        const subscriptionId = await session.subscribe(channel, queries)
        const entry: ActiveSubscription = { id: subscriptionId, channel, queries }
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

  const addConfiguredSubscription = useCallback(
    async (channel: string) => {
      const trimmed = channel.trim()
      if (!trimmed) return

      const entry = addSubscription(trimmed)
      if (!entry) return

      if (connectionStatus === 'connected') {
        await subscribeWebSocket(trimmed, [])
      }
    },
    [addSubscription, connectionStatus, subscribeWebSocket],
  )

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

  const removeConfiguredSubscription = useCallback(
    async (entryId: string) => {
      const configured = configuredSubscriptions.find(
        (subscription) => subscription.id === entryId,
      )
      if (!configured) return

      const queryStrings = entriesToQueryStrings(configured.queries)
      removeSubscription(entryId)

      if (connectionStatus === 'connected') {
        const active = activeSubscriptions.find((subscription) =>
          subscriptionsMatch(
            subscription.channel,
            subscription.queries,
            configured.channel,
            queryStrings,
          ),
        )
        if (active) {
          await handleUnsubscribe(active.id)
        }
      }
    },
    [
      activeSubscriptions,
      configuredSubscriptions,
      connectionStatus,
      handleUnsubscribe,
      removeSubscription,
    ],
  )

  const addConfiguredSubscriptionQuery = useCallback(
    async (
      subscriptionId: string,
      query: SubscriptionQueryEntry,
    ) => {
      const configured = configuredSubscriptions.find(
        (subscription) => subscription.id === subscriptionId,
      )
      if (!configured) return

      const previousQueryStrings = entriesToQueryStrings(configured.queries)
      const wasLive = activeSubscriptions.some((subscription) =>
        subscriptionsMatch(
          subscription.channel,
          subscription.queries,
          configured.channel,
          previousQueryStrings,
        ),
      )

      addSubscriptionQuery(subscriptionId, query)

      if (!wasLive || connectionStatus !== 'connected') return

      const nextQueryStrings = entriesToQueryStrings([
        ...configured.queries,
        query,
      ])
      const active = activeSubscriptions.find((subscription) =>
        subscriptionsMatch(
          subscription.channel,
          subscription.queries,
          configured.channel,
          previousQueryStrings,
        ),
      )
      if (active) {
        await handleUnsubscribe(active.id)
      }
      await subscribeWebSocket(configured.channel, nextQueryStrings)
    },
    [
      activeSubscriptions,
      addSubscriptionQuery,
      configuredSubscriptions,
      connectionStatus,
      handleUnsubscribe,
      subscribeWebSocket,
    ],
  )

  const removeConfiguredSubscriptionQuery = useCallback(
    async (subscriptionId: string, queryId: string) => {
      const configured = configuredSubscriptions.find(
        (subscription) => subscription.id === subscriptionId,
      )
      if (!configured) return

      const previousQueryStrings = entriesToQueryStrings(configured.queries)
      const wasLive = activeSubscriptions.some((subscription) =>
        subscriptionsMatch(
          subscription.channel,
          subscription.queries,
          configured.channel,
          previousQueryStrings,
        ),
      )

      removeSubscriptionQuery(subscriptionId, queryId)

      if (!wasLive || connectionStatus !== 'connected') return

      const nextQueryStrings = entriesToQueryStrings(
        configured.queries.filter((query) => query.id !== queryId),
      )
      const active = activeSubscriptions.find((subscription) =>
        subscriptionsMatch(
          subscription.channel,
          subscription.queries,
          configured.channel,
          previousQueryStrings,
        ),
      )
      if (active) {
        await handleUnsubscribe(active.id)
      }
      await subscribeWebSocket(configured.channel, nextQueryStrings)
    },
    [
      activeSubscriptions,
      configuredSubscriptions,
      connectionStatus,
      handleUnsubscribe,
      removeSubscriptionQuery,
      subscribeWebSocket,
    ],
  )

  const handleChannelBuilt = useCallback(
    async (channel: string) => {
      const trimmed = channel.trim()
      if (!trimmed) return
      await addConfiguredSubscription(trimmed)
    },
    [addConfiguredSubscription],
  )

  const handleOpenChannelBuilder = useCallback(() => {
    openDialogAfterOverlayCloses(() => setChannelBuilderOpen(true))
  }, [])

  const handleClearLogs = useCallback(() => {
    setLogs([])
    setExpandedMessageIds(new Set())
  }, [])

  const allMessagesExpanded = useMemo(
    () =>
      filteredLogs.length > 0 &&
      filteredLogs.every((entry) => expandedMessageIds.has(entry.id)),
    [filteredLogs, expandedMessageIds],
  )

  const handleToggleAllMessages = useCallback(() => {
    if (allMessagesExpanded) {
      setExpandedMessageIds(new Set())
      return
    }

    setExpandedMessageIds(new Set(filteredLogs.map((entry) => entry.id)))
  }, [allMessagesExpanded, filteredLogs])

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
      <ServiceHeader title={t('Realtime')} fullWidthBorder fullWidth />

      <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
        <TooltipProvider delayDuration={300}>
          <div
            className={cn(
              'border-b border-border bg-muted/20 lg:grid',
              REALTIME_LAYOUT_GRID,
            )}
          >
            <div className="flex min-w-0 flex-col gap-2 border-b border-border px-4 py-3 sm:flex-row sm:items-center sm:gap-3 lg:min-h-14 lg:border-b-0 lg:border-e">
              <Tooltip>
                <TooltipTrigger asChild>
                  <span className="cursor-default shrink-0 text-[12px] font-medium text-muted-foreground sm:w-auto">
                    {t('Act as')}
                  </span>
                </TooltipTrigger>
                <TooltipContent side="bottom" className="max-w-xs text-[12px]">
                  {isGuestActAs
                    ? t(
                        'Guest connections do not send a session or JWT. Subscribe only to channels with public read permissions.',
                      )
                    : t(
                        'User connections create a JWT for the selected project user when you connect.',
                      )}
                </TooltipContent>
              </Tooltip>

              <div className="min-w-0 w-full flex-1">
                <SearchableSelect
                  value={actAsValue}
                  onValueChange={setActAsValue}
                  items={actAsItems}
                  placeholder={
                    usersLoading ? t('Loading users…') : t('Select guest or user')
                  }
                  searchPlaceholder={t('Search users or select guest...')}
                  emptyMessage={t('No users found')}
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
              <div className="flex w-full shrink-0 flex-wrap items-center gap-2 sm:w-auto lg:ms-auto">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 flex-1 text-[13px] sm:flex-none"
                  onClick={() => setConnectionCodeOpen(true)}
                >
                  <Code2 className="me-1.5 h-4 w-4" />
                  {t('SDK code')}
                </Button>
                {isConnected ? (
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-9 flex-1 text-[13px] sm:flex-none"
                    onClick={() => void handleDisconnect()}
                    disabled={isConnecting}
                  >
                    {t('Disconnect')}
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
                        <Loader2 className="me-1.5 h-4 w-4 animate-spin" />
                        {t('Connecting')}
                      </>
                    ) : (
                      t('Connect')
                    )}
                  </Button>
                )}
              </div>
            </div>
          </div>
        </TooltipProvider>

        <ReconnectBanner state={reconnectState} />

        <div
          className={cn(
            'flex min-h-0 flex-1 flex-col overflow-hidden lg:grid',
            REALTIME_LAYOUT_GRID,
            'lg:grid-rows-[3rem_minmax(0,1fr)]',
          )}
        >
          <RealtimePanelHeader
            title={t('Subscriptions')}
            className="order-1 lg:col-start-1 lg:row-start-1 lg:border-e lg:border-border"
            actions={
              <span className="rounded-md border border-border bg-muted/30 px-2 py-0.5 font-mono text-[11px] tabular-nums text-muted-foreground">
                {subscriptionCount}
              </span>
            }
          />

          <div className="order-2 flex max-h-[min(50dvh,28rem)] min-h-0 flex-col overflow-hidden border-b border-border lg:col-start-1 lg:row-start-2 lg:max-h-none lg:min-h-0 lg:border-b-0 lg:border-e">
            <ConfigurationPanel
              isConnected={isConnected}
              configuredSubscriptions={configuredSubscriptions}
              isSubscriptionLive={isSubscriptionLive}
              onAddSubscription={addConfiguredSubscription}
              onRemoveSubscription={removeConfiguredSubscription}
              onAddSubscriptionQuery={addConfiguredSubscriptionQuery}
              onRemoveSubscriptionQuery={removeConfiguredSubscriptionQuery}
              onOpenChannelBuilder={handleOpenChannelBuilder}
            />

            <div className="shrink-0 border-t border-border bg-muted/30 px-4 py-3">
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-9 w-full text-[13px]"
                disabled={!canDisconnectAll}
                onClick={() => void handleDisconnectAll()}
              >
                <Unplug className="me-1.5 h-4 w-4" />
                {t('Disconnect all')}
              </Button>
            </div>
          </div>

          <RealtimePanelHeader
            title={t('Messages') /* pragma: allowlist secret */}
            className="order-3 lg:col-start-2 lg:row-start-1"
            actions={
              <>
                <InsertSampleMessageMenu onInsert={appendSampleLog} />
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-7 text-[12px]"
                  onClick={() => setIsPaused((current) => !current)}
                >
                  {isPaused ? (
                    <>
                      <Play className="me-1.5 h-3.5 w-3.5" />
                      {t('Resume')}
                    </>
                  ) : (
                    <>
                      <Pause className="me-1.5 h-3.5 w-3.5" />
                      {t('Pause')}
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
                  <Trash2 className="me-1.5 h-3.5 w-3.5" />
                  {t('Clear')}
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
                          allMessagesExpanded ? t('Collapse all') : t('Expand all') // pragma: allowlist secret
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
                      <p>{allMessagesExpanded ? t('Collapse all') : t('Expand all')}</p> {/* pragma: allowlist secret */}
                    </TooltipContent>
                  </Tooltip>
                </TooltipProvider>
              </>
            }
          />

          <div className="order-4 flex min-h-[280px] flex-col overflow-hidden lg:col-start-2 lg:row-start-2 lg:min-h-0">
            <MessagesFilterBar
              filters={messageFilters}
              onChange={setMessageFilters}
            />
            <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
              {logs.length === 0 ? (
                <div className="flex min-h-[200px] flex-1 items-center justify-center px-4 py-12">
                  <div className="w-full max-w-sm">
                    <MessagesEmptyState
                      isConnected={isConnected}
                      hasSubscriptions={configuredSubscriptions.length > 0}
                    />
                  </div>
                </div>
              ) : filteredLogs.length === 0 ? (
                <div className="flex min-h-[160px] items-center justify-center px-4 py-12 text-center text-[13px] text-muted-foreground">
                  {t('No messages match your filters.')} {/* pragma: allowlist secret */}
                </div>
              ) : (
                <div>
                  {filteredLogs.map((entry, index) => (
                    <MessageRow
                      key={entry.id}
                      entry={entry}
                      sequence={filteredLogs.length - index}
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
        subscriptions={configuredSubscriptions}
      />

      <EventEditorModal
        open={channelBuilderOpen}
        onOpenChange={setChannelBuilderOpen}
        onCreated={handleChannelBuilt}
        projectId={projectId}
        channelMode
        initialValue={undefined}
      />
    </div>
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
  const t = useT()
  const type = entry.message.type || 'unknown'
  const payload = useMemo(
    () => formatMessagePayload(entry.message),
    [entry.message],
  )
  const eventSummary = useMemo(() => {
    if (type !== 'event') return null
    return getEventFrameSummary(entry.message.data)
  }, [entry.message.data, type])

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
        className="grid cursor-pointer grid-cols-[auto_1.75rem_0.875rem_minmax(0,1fr)] items-start gap-x-1.5 px-4 py-2.5 transition-colors hover:bg-muted/30"
      >
        <MessageDirectionIcon
          direction={entry.direction}
          type={entry.message.type || 'unknown'}
        />

        <span className="pt-0.5 text-end font-mono text-[11px] tabular-nums leading-none text-muted-foreground">
          {sequence}
        </span>

        <ChevronRight
          className={cn(
            'mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground transition-transform duration-200',
            expanded && 'rotate-90',
          )}
          aria-hidden
        />

        <div className="flex min-w-0 flex-col gap-1">
          <div className="flex min-w-0 items-center justify-between gap-3">
            <div className="flex min-w-0 items-center gap-1.5">
              <Badge
                variant={messageTypeVariant(type, entry.direction)}
                className="h-5 shrink-0 font-mono text-[10px] uppercase"
              >
                {type}
              </Badge>
              {entry.isSample ? (
                <Badge
                  variant="warning"
                  className="h-5 shrink-0 text-[10px] uppercase"
                >
                  {t('Sample')}
                </Badge>
              ) : null}
            </div>
            <span
              className="shrink-0 font-mono text-[11px] tabular-nums text-muted-foreground"
              title={entry.timestamp}
            >
              {formatLogTimestamp(entry.timestamp)}
            </span>
          </div>
          {!expanded && eventSummary ? (
            <EventFrameSummaryLine summary={eventSummary} />
          ) : null}
        </div>
      </div>

      {expanded ? (
        <div className="grid grid-cols-[auto_1.75rem_0.875rem_minmax(0,1fr)] gap-x-1.5 px-4 pb-3">
          <div aria-hidden />
          <div aria-hidden />
          <div aria-hidden />
          <div className="min-w-0 pt-2">
            {entry.isSample ? (
              <p className="mb-2 rounded-md border border-amber-500/20 bg-amber-500/5 px-3 py-2 text-[12px] leading-snug text-muted-foreground">
                {t(
                  'Sample frame for reference only. Nothing was sent over the network and no project data was changed.',
                )}
              </p>
            ) : null}
            <MessagePayloadBlock payload={payload} message={entry.message} />
          </div>
        </div>
      ) : null}
    </div>
  )
}

function EventFrameSummaryLine({
  summary,
}: {
  summary: NonNullable<ReturnType<typeof getEventFrameSummary>>
}) {
  const parts: string[] = []

  if (summary.events.length > 0) {
    parts.push(`events: ${formatSummaryList(summary.events)}`)
  }
  if (summary.channels.length > 0) {
    parts.push(`channels: ${formatSummaryList(summary.channels)}`)
  }
  if (summary.subscriptionIds.length > 0) {
    parts.push(`subs: ${formatSummaryList(summary.subscriptionIds)}`)
  }

  if (parts.length === 0) return null

  return (
    <p className="truncate font-mono text-[11px] text-muted-foreground">
      {parts.join(' · ')}
    </p>
  )
}
