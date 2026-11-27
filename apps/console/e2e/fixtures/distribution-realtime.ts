import type { Page, WebSocketRoute } from '@playwright/test'

export type DistributionRealtime = {
  connected(): Promise<void>
  emit(events: string[], payload: Record<string, unknown>): void
}

export type DistributionRealtimeOptions = {
  user: Record<string, unknown>
  channels: string[]
}

type Connection = {
  socket: WebSocketRoute
  subscriptions: Map<string, string[]>
}

type Frame = {
  type?: string
  data?: unknown
}

type SubscriptionRow = {
  subscriptionId?: string
  channels?: string[]
}

function parseFrame(message: string | Buffer): Frame {
  try {
    const frame: unknown = JSON.parse(String(message))
    return frame && typeof frame === 'object' ? (frame as Frame) : {}
  } catch {
    return {}
  }
}

function rows(data: unknown): SubscriptionRow[] {
  return Array.isArray(data) ? (data as SubscriptionRow[]) : []
}

/**
 * Mocks every Realtime socket the Console opens, on the main and the regional
 * host, and delivers events to the subscriptions of each open connection that
 * listen on one of `channels`.
 */
export async function mockDistributionRealtime(
  page: Page,
  { user, channels }: DistributionRealtimeOptions,
): Promise<DistributionRealtime> {
  const connections = new Set<Connection>()
  const waiters: (() => void)[] = []

  const listening = (connection: Connection) =>
    [...connection.subscriptions].filter(([, subscribed]) =>
      subscribed.some((channel) => channels.includes(channel)),
    )

  const settle = () => {
    if ([...connections].some((connection) => listening(connection).length)) {
      waiters.splice(0).forEach((resolve) => resolve())
    }
  }

  await page.routeWebSocket(
    (url) => url.pathname.endsWith('/v1/realtime'),
    (socket) => {
      const connection: Connection = { socket, subscriptions: new Map() }
      connections.add(connection)

      socket.onMessage((message) => {
        const frame = parseFrame(message)
        if (frame.type === 'ping') {
          socket.send(JSON.stringify({ type: 'pong' }))
        }
        if (frame.type === 'subscribe') {
          for (const row of rows(frame.data)) {
            if (row.subscriptionId) {
              connection.subscriptions.set(
                row.subscriptionId,
                row.channels ?? [],
              )
            }
          }
          settle()
        }
        if (frame.type === 'unsubscribe') {
          for (const row of rows(frame.data)) {
            if (row.subscriptionId) {
              connection.subscriptions.delete(row.subscriptionId)
            }
          }
        }
      })

      socket.onClose((code, reason) => {
        connections.delete(connection)
        socket.close({ code, reason })
      })

      socket.send(
        JSON.stringify({ type: 'connected', data: { channels: [], user } }),
      )
    },
  )

  return {
    connected: () =>
      new Promise((resolve) => {
        waiters.push(resolve)
        settle()
      }),
    emit: (events, payload) => {
      const timestamp = new Date().toISOString()
      for (const connection of connections) {
        const subscriptions = listening(connection).map(([id]) => id)
        if (!subscriptions.length) continue
        connection.socket.send(
          JSON.stringify({
            type: 'event',
            data: { events, channels, timestamp, payload, subscriptions },
          }),
        )
      }
    },
  }
}
