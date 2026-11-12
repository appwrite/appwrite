import { useQuery } from '@tanstack/react-query'
import { DateRange } from 'react-day-picker'
import { subMinutes, eachMinuteOfInterval, startOfMinute } from 'date-fns'

// Mock data types
export interface RealtimeStats {
  currentConnections: number
  connectionsChange: number
  messagesPerMinute: number
  messagesChange: number
  activeChannels: number
  channelsChange: number
  concurrencyData: Array<{ timestamp: string; connections: number }>
  messagesData: Array<{ timestamp: string; messagesPerMinute: number }>
  channelsData: Array<{ timestamp: string; activeChannels: number }>
}

export interface RealtimeMessage {
  id: string
  channels: string[]
  events: string[]
  payloadSize: string
  timestamp: string
}

export interface RealtimeChannel {
  id: string
  name: string
  type: string
  subscribers: number
  messageCount: number
  lastActivity: string | null
}

// Mock data generators
function generateMockStats(dateRange: DateRange | undefined): RealtimeStats {
  const now = new Date()
  const from = dateRange?.from || subMinutes(now, 60)
  const to = dateRange?.to || now

  // Generate time series data with adaptive step based on date range
  // Limit to maximum ~50 data points for better performance and readability
  const timeDiff = to.getTime() - from.getTime()
  const hoursDiff = timeDiff / (1000 * 60 * 60)
  const daysDiff = hoursDiff / 24
  const totalMinutes = hoursDiff * 60
  const maxPoints = 50

  // Calculate step to ensure we don't exceed maxPoints
  let step = Math.max(5, Math.ceil(totalMinutes / maxPoints))

  // Use larger intervals for longer date ranges to reduce density
  if (daysDiff > 30) {
    // More than 30 days: use 12 hour intervals (720 minutes)
    step = Math.max(step, 720)
  } else if (daysDiff > 7) {
    // More than 7 days: use 4 hour intervals (240 minutes)
    step = Math.max(step, 240)
  } else if (daysDiff > 1) {
    // More than 1 day: use 1 hour intervals (60 minutes)
    step = Math.max(step, 60)
  } else if (hoursDiff > 6) {
    // More than 6 hours: use 15 minute intervals
    step = Math.max(step, 15)
  } else if (hoursDiff > 2) {
    // More than 2 hours: use 10 minute intervals
    step = Math.max(step, 10)
  }
  // Otherwise use calculated step (minimum 5 minutes)

  const minutes = eachMinuteOfInterval(
    { start: startOfMinute(from), end: startOfMinute(to) },
    { step },
  )

  const baseConnections = 150 + Math.random() * 100
  const baseMessages = 500 + Math.random() * 300
  const baseChannels = 25 + Math.random() * 15

  const concurrencyData = minutes.map((minute) => ({
    timestamp: minute.toISOString(),
    connections: Math.floor(baseConnections + (Math.random() - 0.5) * 50),
  }))

  const messagesData = minutes.map((minute) => ({
    timestamp: minute.toISOString(),
    messagesPerMinute: Math.floor(baseMessages + (Math.random() - 0.5) * 200),
  }))

  const channelsData = minutes.map((minute) => ({
    timestamp: minute.toISOString(),
    activeChannels: Math.floor(baseChannels + (Math.random() - 0.5) * 10),
  }))

  return {
    currentConnections: Math.floor(baseConnections),
    connectionsChange: Number(((Math.random() - 0.3) * 20).toFixed(2)), // -30% to +17%
    messagesPerMinute: Math.floor(baseMessages),
    messagesChange: Number(((Math.random() - 0.3) * 20).toFixed(2)),
    activeChannels: Math.floor(baseChannels),
    channelsChange: Number(((Math.random() - 0.3) * 20).toFixed(2)),
    concurrencyData,
    messagesData,
    channelsData,
  }
}

function generateMockMessages(
  page: number,
  limit: number,
  dateRange: DateRange | undefined,
): { messages: RealtimeMessage[]; total: number } {
  const total = 1247 // Mock total
  const startIndex = page * limit
  const endIndex = Math.min(startIndex + limit, total)

  const messages: RealtimeMessage[] = []
  const now = new Date()
  const from = dateRange?.from || subMinutes(now, 60)
  const to = dateRange?.to || now

  const channelTypes = [
    'account',
    'databases.default.tables.users.rows',
    'databases.default.tables.posts.rows.abc123',
    'files',
    'buckets.uploads.files',
    'teams',
    'memberships',
  ]

  const eventTypes = [
    'databases.default.tables.users.rows.create',
    'databases.default.tables.users.rows.update',
    'databases.default.tables.users.rows.delete',
    'files.create',
    'files.update',
    'files.delete',
    'account.sessions.create',
    'account.name.update',
  ]

  for (let i = startIndex; i < endIndex; i++) {
    const timestamp = new Date(
      from.getTime() + Math.random() * (to.getTime() - from.getTime()),
    )

    const numChannels = Math.floor(Math.random() * 3) + 1
    const numEvents = Math.floor(Math.random() * 4) + 1

    messages.push({
      id: `msg_${i.toString().padStart(8, '0')}`,
      channels: Array.from(
        { length: numChannels },
        () => channelTypes[Math.floor(Math.random() * channelTypes.length)],
      ),
      events: Array.from(
        { length: numEvents },
        () => eventTypes[Math.floor(Math.random() * eventTypes.length)],
      ),
      payloadSize: `${Math.floor(Math.random() * 5000 + 100)} bytes`,
      timestamp: timestamp.toISOString(),
    })
  }

  return { messages, total }
}

function generateMockChannels(
  page: number,
  limit: number,
  dateRange: DateRange | undefined,
): { channels: RealtimeChannel[]; total: number } {
  const total = 89 // Mock total
  const startIndex = page * limit
  const endIndex = Math.min(startIndex + limit, total)

  const channels: RealtimeChannel[] = []
  const now = new Date()
  const from = dateRange?.from || subMinutes(now, 60)
  const to = dateRange?.to || now

  const channelNames = [
    'account',
    'databases.default.tables.users.rows',
    'databases.default.tables.posts.rows',
    'databases.default.tables.comments.rows',
    'files',
    'buckets.uploads.files',
    'buckets.images.files',
    'teams',
    'memberships',
    'rows',
    'databases.default.tables.products.rows',
  ]

  const types = ['account', 'database', 'storage', 'teams', 'general']

  for (let i = startIndex; i < endIndex; i++) {
    const channelName =
      channelNames[Math.floor(Math.random() * channelNames.length)]
    const lastActivity =
      Math.random() > 0.3
        ? new Date(
            from.getTime() + Math.random() * (to.getTime() - from.getTime()),
          ).toISOString()
        : null

    channels.push({
      id: `ch_${i.toString().padStart(8, '0')}`,
      name: channelName,
      type: types[Math.floor(Math.random() * types.length)],
      subscribers: Math.floor(Math.random() * 500 + 10),
      messageCount: Math.floor(Math.random() * 10000 + 100),
      lastActivity,
    })
  }

  return { channels, total }
}

// Hooks
export function useRealtimeStats(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
) {
  return useQuery({
    queryKey: ['realtime', 'stats', projectId, dateRange],
    queryFn: () => {
      // Simulate API delay
      return new Promise<RealtimeStats>((resolve) => {
        setTimeout(() => {
          resolve(generateMockStats(dateRange))
        }, 300)
      })
    },
    enabled: !!projectId,
    staleTime: 10000, // 10 seconds
  })
}

export function useRealtimeMessages(
  projectId: string | null | undefined,
  page: number,
  limit: number,
  dateRange: DateRange | undefined,
) {
  const { data, ...rest } = useQuery({
    queryKey: ['realtime', 'messages', projectId, page, limit, dateRange],
    queryFn: () => {
      return new Promise<{ messages: RealtimeMessage[]; total: number }>(
        (resolve) => {
          setTimeout(() => {
            resolve(generateMockMessages(page, limit, dateRange))
          }, 300)
        },
      )
    },
    enabled: !!projectId,
    staleTime: 10000,
  })

  return {
    messages: data?.messages || [],
    total: data?.total || 0,
    ...rest,
  }
}

export function useRealtimeChannels(
  projectId: string | null | undefined,
  page: number,
  limit: number,
  dateRange: DateRange | undefined,
) {
  const { data, ...rest } = useQuery({
    queryKey: ['realtime', 'channels', projectId, page, limit, dateRange],
    queryFn: () => {
      return new Promise<{ channels: RealtimeChannel[]; total: number }>(
        (resolve) => {
          setTimeout(() => {
            resolve(generateMockChannels(page, limit, dateRange))
          }, 300)
        },
      )
    },
    enabled: !!projectId,
    staleTime: 10000,
  })

  return {
    channels: data?.channels || [],
    total: data?.total || 0,
    ...rest,
  }
}
