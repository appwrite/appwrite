import { Client, ID } from '@appwrite.io/console'
import { getProjectApiEndpoint, sdk } from '@/lib/appwrite/sdk'

export async function createUserJwtForRealtime(
  projectId: string,
  userId: string,
): Promise<string> {
  const response = await sdk.forProject(projectId).users.createJWT({ userId })
  if (!response.jwt?.trim()) {
    throw new Error('Failed to create JWT: empty response')
  }
  return response.jwt
}

export type RealtimeSocketMessage = {
  type: string
  data?: unknown
}

export type RealtimeMessageDirection = 'in' | 'out'

export type RealtimeMessageLog = {
  direction: RealtimeMessageDirection
  timestamp: string
  message: RealtimeSocketMessage
}

type SubscriptionRecord = {
  id: string
  channels: string[]
  queries: string[]
}

export type RealtimeSessionAuth =
  | { mode: 'guest' }
  | { mode: 'user'; jwt: string }

export type RealtimeSessionError = {
  message: string
  code?: number
}

export type RealtimeReconnectState = {
  status: 'idle' | 'scheduled' | 'connecting'
  attempt: number
  maxAttempts: number
  delayMs?: number
}

export type RealtimeSessionCallbacks = {
  onMessage: (log: RealtimeMessageLog) => void
  onOpen?: () => void
  onClose?: (event: CloseEvent) => void
  onError?: (error: RealtimeSessionError) => void
  onReconnectStateChange?: (state: RealtimeReconnectState) => void
}

export type RealtimeSession = {
  connect: () => Promise<void>
  subscribe: (channel: string, queries?: string[]) => Promise<string>
  unsubscribe: (subscriptionId: string) => Promise<void>
  disconnectAll: () => Promise<void>
  disconnect: () => Promise<void>
}

const HEARTBEAT_MS = 20_000
const POLICY_VIOLATION_CODE = 1008
const MAX_RECONNECT_ATTEMPTS = 8

export function toWebSocketEndpoint(httpEndpoint: string): string {
  return httpEndpoint
    .replace(/^https:\/\//, 'wss://')
    .replace(/^http:\/\//, 'ws://')
}

/** Full WebSocket URL used to connect (includes project and optional JWT query params). */
function buildRealtimeUrl(client: Client): string {
  const endpoint =
    client.config.endpointRealtime !== ''
      ? client.config.endpointRealtime
      : client.config.endpoint || ''
  const realtimeEndpoint = toWebSocketEndpoint(endpoint)
  const params = new URLSearchParams()
  params.set('project', client.config.project)

  const jwt = client.config.jwt.trim()
  if (jwt) {
    params.set('jwt', jwt)
  }

  return `${realtimeEndpoint}/realtime?${params.toString()}`
}

/** WebSocket URL shown in the Realtime UI (project only; never includes JWT). */
export function getProjectRealtimeWebSocketUrl(projectId: string): string {
  const base = `${toWebSocketEndpoint(getProjectApiEndpoint(projectId))}/realtime`
  return `${base}?project=${encodeURIComponent(projectId)}`
}

/**
 * Isolated Appwrite client for the Realtime debugger.
 * Omits credentials so the SDK never falls back to console session cookies.
 * When `jwt` is provided (user mode), it is set on the client for Realtime auth.
 */
export function createIsolatedRealtimeClient(
  projectId: string,
  options?: { jwt?: string },
): Client {
  const client = new Client()
  client
    .setEndpoint(getProjectApiEndpoint(projectId))
    .setProject(projectId)
    .setCredentials('omit')
  client.setSession('')
  client.setJWT(options?.jwt?.trim() ?? '')
  client.setCookie('')
  client.setKey('')
  return client
}

function sendMessage(
  socket: WebSocket,
  message: RealtimeSocketMessage,
  onMessage: RealtimeSessionCallbacks['onMessage'],
) {
  socket.send(JSON.stringify(message))

  onMessage({
    direction: 'out',
    timestamp: new Date().toISOString(),
    message,
  })
}

function reconnectDelayMs(attempt: number): number {
  if (attempt < 5) return 1000
  if (attempt < 15) return 5000
  return 10_000
}

function isFatalRealtimeErrorCode(code: number | undefined): boolean {
  if (code === undefined) return false
  if (code === POLICY_VIOLATION_CODE) return true
  return code >= 400 && code < 500
}

/**
 * Isolated Realtime WebSocket session for the console UI.
 * Logs every inbound and outbound frame (ping, pong, connected, subscribe, etc.).
 */
export function createRealtimeSession(
  projectId: string,
  auth: RealtimeSessionAuth,
  callbacks: RealtimeSessionCallbacks,
): RealtimeSession {
  const jwt = auth.mode === 'user' ? auth.jwt.trim() : ''
  const client = createIsolatedRealtimeClient(
    projectId,
    jwt ? { jwt } : undefined,
  )
  const realtimeUrl = buildRealtimeUrl(client)

  let socket: WebSocket | null = null
  let connectionId = 0
  let heartbeatTimer: ReturnType<typeof setInterval> | undefined
  let reconnectTimer: ReturnType<typeof setTimeout> | undefined
  let shouldReconnect = false
  let closedByUser = false
  let connectionRequested = false
  let appConnected = false
  let reconnectAttempts = 0
  let fatalError: RealtimeSessionError | null = null

  const isSessionActive = () => connectionRequested || subscriptions.size > 0

  const subscriptions = new Map<string, SubscriptionRecord>()
  const pendingSubscribes = new Map<string, SubscriptionRecord>()

  const emitReconnectState = (state: RealtimeReconnectState) => {
    callbacks.onReconnectStateChange?.(state)
  }

  const clearHeartbeat = () => {
    if (heartbeatTimer) {
      clearInterval(heartbeatTimer)
      heartbeatTimer = undefined
    }
  }

  const clearReconnectTimer = () => {
    if (reconnectTimer) {
      clearTimeout(reconnectTimer)
      reconnectTimer = undefined
    }
  }

  const startHeartbeat = (activeId: number) => {
    clearHeartbeat()
    heartbeatTimer = setInterval(() => {
      if (activeId !== connectionId || !socket || socket.readyState !== WebSocket.OPEN) {
        return
      }
      sendMessage(socket, { type: 'ping' }, callbacks.onMessage)
    }, HEARTBEAT_MS)
  }

  const flushPendingSubscribes = () => {
    if (!socket || socket.readyState !== WebSocket.OPEN || !appConnected) return
    if (pendingSubscribes.size === 0) return

    const rows = Array.from(pendingSubscribes.values()).map((entry) => ({
      subscriptionId: entry.id,
      channels: entry.channels,
      queries: entry.queries,
    }))
    pendingSubscribes.clear()

    sendMessage(
      socket,
      {
        type: 'subscribe',
        data: rows,
      },
      callbacks.onMessage,
    )
  }

  const enqueuePendingSubscribe = (subscriptionId: string) => {
    const record = subscriptions.get(subscriptionId)
    if (!record) return
    pendingSubscribes.set(subscriptionId, record)
  }

  const handleIncomingMessage = (raw: RealtimeSocketMessage, activeId: number) => {
    if (activeId !== connectionId) return

    callbacks.onMessage({
      direction: 'in',
      timestamp: new Date().toISOString(),
      message: raw,
    })

    switch (raw.type) {
      case 'connected': {
        appConnected = true
        reconnectAttempts = 0
        emitReconnectState({
          status: 'idle',
          attempt: 0,
          maxAttempts: MAX_RECONNECT_ATTEMPTS,
        })

        for (const subscriptionId of subscriptions.keys()) {
          enqueuePendingSubscribe(subscriptionId)
        }
        flushPendingSubscribes()
        break
      }
      case 'error': {
        const data = raw.data as { message?: string; code?: number } | undefined
        const sessionError: RealtimeSessionError = {
          message: data?.message || 'Realtime error',
          code: data?.code,
        }
        if (isFatalRealtimeErrorCode(data?.code)) {
          fatalError = sessionError
          shouldReconnect = false
        }
        callbacks.onError?.(sessionError)
        break
      }
      default:
        break
    }
  }

  const closeActiveSocket = () => {
    clearHeartbeat()
    clearReconnectTimer()
    const active = socket
    socket = null
    appConnected = false

    if (active && active.readyState < WebSocket.CLOSING) {
      active.close(1000)
    }
  }

  const scheduleReconnect = () => {
    clearReconnectTimer()
    if (closedByUser || !shouldReconnect || !isSessionActive() || fatalError) return

    if (reconnectAttempts >= MAX_RECONNECT_ATTEMPTS) {
      shouldReconnect = false
      emitReconnectState({
        status: 'idle',
        attempt: reconnectAttempts,
        maxAttempts: MAX_RECONNECT_ATTEMPTS,
      })
      callbacks.onMessage({
        direction: 'in',
        timestamp: new Date().toISOString(),
        message: {
          type: 'error',
          data: {
            message: 'Realtime connection failed after multiple attempts.',
          },
        },
      })
      callbacks.onError?.({
        message: 'Realtime connection failed after multiple attempts.',
      })
      return
    }

    const delay = reconnectDelayMs(reconnectAttempts)
    emitReconnectState({
      status: 'scheduled',
      attempt: reconnectAttempts + 1,
      maxAttempts: MAX_RECONNECT_ATTEMPTS,
      delayMs: delay,
    })
    reconnectTimer = setTimeout(() => {
      reconnectTimer = undefined
      if (closedByUser || !shouldReconnect || !isSessionActive() || fatalError) return
      reconnectAttempts += 1
      emitReconnectState({
        status: 'connecting',
        attempt: reconnectAttempts,
        maxAttempts: MAX_RECONNECT_ATTEMPTS,
      })
      openSocket()
    }, delay)
  }

  const openSocket = () => {
    if (!isSessionActive()) {
      shouldReconnect = false
      closeActiveSocket()
      return
    }

    if (fatalError) {
      shouldReconnect = false
      closeActiveSocket()
      return
    }

    if (socket?.readyState === WebSocket.OPEN) {
      if (appConnected) flushPendingSubscribes()
      return
    }

    if (socket?.readyState === WebSocket.CONNECTING) {
      return
    }

    closeActiveSocket()

    shouldReconnect = true
    const activeId = ++connectionId
    if (reconnectAttempts > 0) {
      emitReconnectState({
        status: 'connecting',
        attempt: reconnectAttempts,
        maxAttempts: MAX_RECONNECT_ATTEMPTS,
      })
    }
    const ws = new WebSocket(realtimeUrl)
    socket = ws

    ws.addEventListener('open', () => {
      if (activeId !== connectionId || ws !== socket || fatalError) return
      callbacks.onMessage({
        direction: 'in',
        timestamp: new Date().toISOString(),
        message: { type: 'open', data: { status: 'WebSocket opened' } },
      })
      callbacks.onOpen?.()
      startHeartbeat(activeId)
    })

    ws.addEventListener('message', (event) => {
      if (activeId !== connectionId || ws !== socket) return
      try {
        const parsed = JSON.parse(String(event.data)) as RealtimeSocketMessage
        handleIncomingMessage(parsed, activeId)
      } catch (error) {
        const message =
          error instanceof Error
            ? error.message
            : 'Failed to parse Realtime message'
        callbacks.onMessage({
          direction: 'in',
          timestamp: new Date().toISOString(),
          message: { type: 'error', data: { message } },
        })
        callbacks.onError?.({ message })
      }
    })

    ws.addEventListener('close', (event) => {
      if (activeId !== connectionId || ws !== socket) return

      clearHeartbeat()
      appConnected = false

      callbacks.onMessage({
        direction: 'in',
        timestamp: new Date().toISOString(),
        message: {
          type: 'close',
          data: {
            code: event.code,
            reason: event.reason || null,
            wasClean: event.wasClean,
          },
        },
      })
      callbacks.onClose?.(event)

      if (event.code === POLICY_VIOLATION_CODE) {
        shouldReconnect = false
        emitReconnectState({
          status: 'idle',
          attempt: 0,
          maxAttempts: MAX_RECONNECT_ATTEMPTS,
        })
        return
      }

      if (closedByUser || !shouldReconnect) {
        emitReconnectState({
          status: 'idle',
          attempt: 0,
          maxAttempts: MAX_RECONNECT_ATTEMPTS,
        })
        return
      }
      scheduleReconnect()
    })

    ws.addEventListener('error', () => {
      if (activeId !== connectionId || ws !== socket || fatalError) return
      const sessionError: RealtimeSessionError = {
        message: 'WebSocket error',
      }
      callbacks.onMessage({
        direction: 'in',
        timestamp: new Date().toISOString(),
        message: { type: 'error', data: { message: sessionError.message } },
      })
      callbacks.onError?.(sessionError)
    })
  }

  const createSubscriptionId = (): string => {
    const attempts = subscriptions.size + 1
    for (let i = 0; i < attempts; i++) {
      const candidate = ID.unique()
      if (!subscriptions.has(candidate)) return candidate
    }
    throw new Error('Failed to generate unique subscription id')
  }

  const closeSession = () => {
    closedByUser = true
    shouldReconnect = false
    connectionRequested = false
    fatalError = null
    subscriptions.clear()
    pendingSubscribes.clear()
    closeActiveSocket()
    connectionId += 1
    reconnectAttempts = 0
    emitReconnectState({
      status: 'idle',
      attempt: 0,
      maxAttempts: MAX_RECONNECT_ATTEMPTS,
    })
    closedByUser = false
  }

  return {
    async connect() {
      connectionRequested = true
      fatalError = null
      shouldReconnect = true
      openSocket()
    },

    async subscribe(channel: string, queries: string[] = []) {
      const trimmed = channel.trim()
      if (!trimmed) {
        throw new Error('Channel is required')
      }

      const subscriptionId = createSubscriptionId()
      const record: SubscriptionRecord = {
        id: subscriptionId,
        channels: [trimmed],
        queries,
      }

      subscriptions.set(subscriptionId, record)
      enqueuePendingSubscribe(subscriptionId)
      openSocket()

      if (appConnected) {
        flushPendingSubscribes()
      }

      return subscriptionId
    },

    async unsubscribe(subscriptionId: string) {
      subscriptions.delete(subscriptionId)
      pendingSubscribes.delete(subscriptionId)

      if (
        socket &&
        socket.readyState === WebSocket.OPEN &&
        appConnected &&
        subscriptionId.trim()
      ) {
        sendMessage(
          socket,
          {
            type: 'unsubscribe',
            data: [{ subscriptionId }],
          },
          callbacks.onMessage,
        )
      }

      if (subscriptions.size === 0 && !connectionRequested) {
        shouldReconnect = false
        closeActiveSocket()
      }
    },

    async disconnectAll() {
      if (
        socket &&
        socket.readyState === WebSocket.OPEN &&
        appConnected &&
        subscriptions.size > 0
      ) {
        const rows = Array.from(subscriptions.keys())
          .filter((subscriptionId) => subscriptionId.trim())
          .map((subscriptionId) => ({ subscriptionId }))
        if (rows.length > 0) {
          sendMessage(
            socket,
            {
              type: 'unsubscribe',
              data: rows,
            },
            callbacks.onMessage,
          )
        }
      }

      closeSession()
    },

    async disconnect() {
      closeSession()
    },
  }
}
